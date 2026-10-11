<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use App\Platform\Auth\AuthSession;
use App\Platform\Auth\PasswordHasher;
use App\Platform\Auth\SessionExpiredException;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityEvent;
use App\Platform\Security\SecurityEventRecorder;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Authentication endpoints (doc 25 §2.2, §2.4, §4; ADR-0006). Credentials are only ever touched through the
 * eora_auth_web connection and the reviewed auth_api functions; everything tenant-related runs as eora_app inside a
 * TenantTransaction with the signed context.
 */
final class AuthController
{
    public function __construct(
        private readonly AuthSession $auth,
        private readonly PasswordHasher $passwords,
        private readonly TenantTransaction $transaction,
        private readonly SecurityHasher $hasher,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $authDb = DB::connection('pgsql_auth');
        $row = $authDb->selectOne('select user_id, password_hash, status from auth_api.login_lookup(?)', [$data['email']]);
        $ipHash = $this->hasher->hash($request->ip());
        $emailHmac = $this->hasher->hash(mb_strtolower(trim($data['email'])));

        // constant work: a dummy hash is verified when there is none (unknown, locked, disabled, passwordless)
        $valid = $this->passwords->verify($data['password'], $row->password_hash ?? null);

        if (! $valid || $row === null || $row->status !== 'ACTIVE') {
            if ($row !== null && $row->status === 'ACTIVE') {
                $authDb->select('select auth_api.record_login_failure(?, ?)', [$row->user_id, $ipHash]);
            }
            $action = match ($row->status ?? null) {
                'LOCKED' => 'auth.locked',
                'DISABLED' => 'auth.disabled_login',
                default => 'auth.failed',
            };
            $this->authEvent($action, $emailHmac, $ipHash, $request);

            throw new AuthenticationException; // one uniform 401 for every failure
        }

        $version = $this->credentialVersion((string) $row->user_id);
        $this->auth->start((string) $row->user_id, $version);
        $this->authEvent('auth.login', $emailHmac, $ipHash, $request);

        return $this->me();
    }

    public function logout(Request $request): Response
    {
        if ($this->auth->userId() !== null) {
            $this->authEvent('auth.logout', null, $this->hasher->hash($request->ip()), $request);
        }
        $this->auth->end();

        return response()->noContent();
    }

    public function me(): JsonResponse
    {
        $user = $this->requireSession();

        $profile = $this->transaction->run(TenantContext::tenantless($user), fn (EoraPostgresConnection $db) => [
            'user' => $db->selectOne('select id, display_name, email_display, locale, credential_version from users where id = ?', [$user]),
            'tenants' => $db->select('select t.id, t.code, t.name from tenants t order by t.name'),
        ]);
        $this->assertCredentialVersion($profile['user']->credential_version ?? null);

        $plants = [];
        if ($this->auth->tenantId() !== null) {
            $plants = $this->transaction->run(new TenantContext($this->auth->tenantId(), $user), function (EoraPostgresConnection $db) use ($user) {
                // the active tenant stays valid only while the membership is ACTIVE
                if ($db->selectOne("select 1 as ok from memberships where user_id = ? and status = 'ACTIVE'", [$user]) === null) {
                    return null;
                }

                return $db->select('select id, code, name from plants order by code');
            });
            if ($plants === null) {
                $this->auth->end();

                throw new SessionExpiredException('Membership no longer active.');
            }
        }

        return response()->json([
            'user' => [
                'id' => $profile['user']->id,
                'displayName' => $profile['user']->display_name,
                'email' => $profile['user']->email_display,
                'locale' => $profile['user']->locale,
            ],
            'tenants' => array_map(static fn (stdClass $t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name], $profile['tenants']),
            'activeTenantId' => $this->auth->tenantId(),
            'plants' => array_map(static fn (stdClass $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name], $plants),
            'activePlantId' => $this->auth->plantId(),
        ]);
    }

    /** Tenant picker (DD-07): only a tenant where the user holds an ACTIVE membership; session regenerated. */
    public function selectTenant(Request $request): JsonResponse
    {
        $user = $this->requireSession();
        $tenant = (string) $request->validate(['tenantId' => ['required', 'uuid']])['tenantId'];

        $allowed = $this->transaction->run(TenantContext::tenantless($user), fn (EoraPostgresConnection $db) => $db->selectOne(
            'select 1 as ok from tenants where id = ?', [strtolower($tenant)],
        ));
        if ($allowed === null) {
            throw new NotFoundHttpException; // not a member: indistinguishable from a missing tenant
        }
        $this->auth->switchTenant(strtolower($tenant));

        return $this->me();
    }

    /** Plant switch inside the active tenant; RLS decides what exists. */
    public function selectPlant(Request $request): JsonResponse
    {
        $user = $this->requireSession();
        $plant = strtolower((string) $request->validate(['plantId' => ['required', 'uuid']])['plantId']);
        $tenant = $this->auth->tenantId() ?? throw ValidationException::withMessages(['plantId' => 'Select a tenant first.']);

        $exists = $this->transaction->run(new TenantContext($tenant, $user), fn (EoraPostgresConnection $db) => $db->selectOne('select 1 as ok from plants where id = ?', [$plant]));
        if ($exists === null) {
            throw new NotFoundHttpException;
        }
        $this->auth->switchPlant($plant);

        return $this->me();
    }

    /**
     * Invitation acceptance (doc 25 §2.4): the delivered secret sets the first password and the membership becomes
     * ACTIVE (D-B1-19). Uniform failure for unknown, expired, replayed or revoked invitations.
     */
    public function acceptInvitation(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);
        $accepted = DB::connection('pgsql_auth')->selectOne(
            'select user_id, tenant_id, membership_id from auth_api.consume_invitation(?, ?)',
            [$data['token'], $this->passwords->hash($data['password'])],
        );

        if ($accepted === null || $accepted->user_id === null) {
            throw ValidationException::withMessages(['token' => 'This invitation link is invalid or has expired.']);
        }

        // The invitation is consumed (and the first password set) in the auth transaction; activation runs as the user in
        // the tenant. Retried once (doc 25 §2.4); a membership already ACTIVE counts as done.
        $context = new TenantContext((string) $accepted->tenant_id, (string) $accepted->user_id);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $status = $this->transaction->run($context, function (EoraPostgresConnection $db) use ($accepted): ?string {
                    $db->update("update memberships set status = 'ACTIVE', activated_at = now() where id = ? and status = 'INVITED'", [$accepted->membership_id]);

                    return $db->selectOne('select status from memberships where id = ?', [$accepted->membership_id])->status ?? null;
                });
                if ($status === 'ACTIVE') {
                    return response()->noContent();
                }
            } catch (Throwable $e) {
                Log::warning('invitation_activation_attempt_failed', ['membership_id' => $accepted->membership_id, 'attempt' => $attempt, 'error' => class_basename($e)]);
            }
        }

        // Still not active (e.g. revoked concurrently, or the database refused twice): leave a durable trace for the
        // tenant's administrators and tell the invitee to contact them — never a false success.
        try {
            SecurityEventRecorder::write($this->transaction, $context, [new SecurityEvent('invitation.activation_failed', resource: 'memberships', requestedId: str_replace('-', '', (string) $accepted->membership_id), httpStatus: 409)]);
        } catch (Throwable $e) {
            Log::error('invitation_activation_failed_unrecorded', ['membership_id' => $accepted->membership_id, 'tenant_id' => $accepted->tenant_id, 'error' => class_basename($e)]);
        }

        throw new ConflictHttpException('Invitation accepted but the membership could not be activated.');
    }

    private function requireSession(): string
    {
        return $this->auth->requireUser();
    }

    /** Password set/reset/change bumps credential_version: every older session dies on its next request (R-39). */
    private function assertCredentialVersion(mixed $current): void
    {
        if ($current === null || (int) $current !== $this->auth->credentialVersion()) {
            $this->auth->end();

            throw new SessionExpiredException('Credentials changed.');
        }
    }

    private function credentialVersion(string $user): int
    {
        return (int) $this->transaction->run(TenantContext::tenantless($user), fn (EoraPostgresConnection $db) => $db->selectOne(
            'select credential_version from users where id = ?', [$user],
        )->credential_version);
    }

    private function authEvent(string $action, ?string $emailHmac, ?string $ipHash, Request $request): void
    {
        $correlation = (string) (Context::get('correlation_id') ?? '');

        DB::connection('pgsql_auth')->select('select auth_api.record_security_event(?, null, ?, ?, ?, ?)', [
            $action, $emailHmac, $ipHash, $this->hasher->hash((string) $request->userAgent()), $correlation !== '' ? $correlation : null,
        ]);
    }
}
