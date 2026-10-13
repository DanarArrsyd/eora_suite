<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Auth\AuthSession;
use App\Platform\Auth\PasswordHasher;
use App\Platform\Auth\PasswordPolicy;
use App\Platform\Auth\SessionExpiredException;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use stdClass;
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
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
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
            'tenants' => $db->select('select t.id, t.code, t.name, t.timezone from tenants t order by t.name'),
        ]);
        $this->assertCredentialVersion($profile['user']->credential_version ?? null);

        $plants = [];
        $permissions = [];
        if ($this->auth->tenantId() !== null) {
            $tenant = $this->transaction->run(new TenantContext($this->auth->tenantId(), $user), function (EoraPostgresConnection $db) use ($user) {
                // the active tenant stays valid only while the membership is ACTIVE
                if ($db->selectOne("select 1 as ok from memberships where user_id = ? and status = 'ACTIVE'", [$user]) === null) {
                    return null;
                }

                return ['plants' => $this->authorizer->plants($db), 'permissions' => $this->authorizer->permissions($db)];
            });
            if ($tenant === null) {
                $this->auth->end();

                throw new SessionExpiredException('Membership no longer active.');
            }
            ['plants' => $plants, 'permissions' => $permissions] = $tenant;
            $active = $this->auth->plantId();
            if ($active !== null && ! in_array($active, array_map(static fn (stdClass $p) => (string) $p->id, $plants), true)) {
                $this->auth->clearPlant();
            }
        }

        return response()->json([
            'user' => [
                'id' => $profile['user']->id,
                'displayName' => $profile['user']->display_name,
                'email' => $profile['user']->email_display,
                'locale' => $profile['user']->locale,
            ],
            /**
             * Tenants where the user holds an ACTIVE membership (tenant picker). `timezone` is the tenant's IANA zone
             * (D-044), used to read calendar dates such as a role's end date.
             *
             * @var list<array{id: string, code: string, name: string, timezone: string}>
             */
            'tenants' => array_map(static fn (stdClass $t) => ['id' => (string) $t->id, 'code' => (string) $t->code, 'name' => (string) $t->name, 'timezone' => (string) $t->timezone], $profile['tenants']),
            'activeTenantId' => $this->auth->tenantId(),
            /**
             * Plants of the active tenant inside the user's assignment scopes (empty while no tenant is selected).
             *
             * @var list<array{id: string, code: string, name: string}>
             */
            'plants' => array_map(static fn (stdClass $p) => ['id' => (string) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name], $plants),
            'activePlantId' => $this->auth->plantId(),
            /**
             * Permission codes held anywhere in the active tenant: a navigation hint only, every endpoint authorizes itself.
             *
             * @var list<string>
             */
            'permissions' => $permissions,
        ]);
    }

    /**
     * Tenant picker (DD-07): only a tenant where the user holds an ACTIVE membership; session regenerated.
     *
     * The switch is recorded as security event `workspace.tenant_selected` of the chosen tenant before the session
     * changes: no record, no switch.
     */
    public function selectTenant(Request $request): JsonResponse
    {
        $user = $this->requireSession();
        $tenant = strtolower((string) $request->validate(['tenantId' => ['required', 'uuid']])['tenantId']);

        $allowed = $this->transaction->run(TenantContext::tenantless($user), fn (EoraPostgresConnection $db) => $db->selectOne(
            'select 1 as ok from tenants where id = ?', [$tenant],
        ));
        if ($allowed === null) {
            throw new NotFoundHttpException; // not a member: indistinguishable from a missing tenant
        }
        $context = new TenantContext($tenant, $user);
        SecurityEventRecorder::write($this->transaction, $context, [$this->workspaceEvent('workspace.tenant_selected', 'tenants', $tenant, 200, $request)]);
        $this->auth->switchTenant($tenant);

        return $this->me();
    }

    /**
     * Plant switch inside the active tenant, limited to plants covered by the user's current assignments.
     *
     * Any other plant answers 404 like a missing one. Success (`workspace.plant_selected`) and refusal
     * (`workspace.plant_denied`) are recorded as security events in the same transaction as the check.
     */
    public function selectPlant(Request $request): JsonResponse
    {
        $user = $this->requireSession();
        $plant = strtolower((string) $request->validate(['plantId' => ['required', 'uuid']])['plantId']);
        $tenant = $this->auth->tenantId() ?? throw ValidationException::withMessages(['plantId' => 'Select a tenant first.']);

        $context = new TenantContext($tenant, $user);
        $allowed = $this->transaction->run($context, function (EoraPostgresConnection $db) use ($context, $plant, $request): bool {
            $allowed = $this->authorizer->plants($db, $plant) !== [];
            SecurityEventRecorder::insert($db, $context, [$allowed
                ? $this->workspaceEvent('workspace.plant_selected', 'plants', $plant, 200, $request)
                : $this->workspaceEvent('workspace.plant_denied', 'plants', $plant, 404, $request)]);

            return $allowed;
        });
        if (! $allowed) {
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
            'password' => ['bail', 'required', 'string', 'min:12', 'max:1024', 'confirmed', new PasswordPolicy],
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
                    if ($db->update("update memberships set status = 'ACTIVE', activated_at = now() where id = ? and status = 'INVITED'", [$accepted->membership_id]) === 1) {
                        $this->audit->record($db, 'membership.activated', 'membership', (string) $accepted->membership_id, ['status' => 'INVITED'], ['status' => 'ACTIVE']);
                    }

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

        throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Membership not activated', 'Your invitation was accepted but the membership could not be activated. Please contact your administrator.');
    }

    /**
     * Forgot password (doc 25 §2.3): always 202 with the same body and headers after a fixed minimum duration (D-B1-21:
     * 400 ms), so whether the email exists, is throttled or was queued is not observable (T-RST-07, T-RSTU-10). The HTTP
     * limits (3/min per email HMAC, 10/min per IP) run here rather than as throttle middleware, which would answer 429
     * or add rate-limit headers.
     */
    public function forgotPassword(Request $request): Response
    {
        $started = hrtime(true);
        $email = $request->validate(['email' => ['required', 'string', 'max:254']])['email'];

        $ipHash = $this->hasher->hash($request->ip());
        $keys = ['forgot:account:'.$this->hasher->hash(mb_strtolower(trim($email))) => 3, 'forgot:ip:'.$ipHash => 10];
        $throttled = false;
        foreach ($keys as $key => $max) {
            $throttled = RateLimiter::tooManyAttempts($key, $max) || $throttled;
        }

        if (! $throttled) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, 60);
            }
            try {
                DB::connection('pgsql_auth')->select('select auth_api.request_password_reset(?, ?)', [$email, $ipHash]);
            } catch (Throwable $e) {
                Log::warning('password_reset_request_failed', ['error' => class_basename($e)]); // same answer either way
            }
        }

        $remaining = 400_000_000 - (hrtime(true) - $started);
        if ($remaining > 0) {
            usleep(intdiv($remaining, 1000));
        }

        return response()->noContent(202);
    }

    /**
     * Reset with the delivered secret (doc 25 §2.3 step 3). Success bumps credential_version, so every existing session
     * of the user — including this one if signed in — ends on its next request.
     */
    public function resetPassword(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:1024', 'confirmed', new PasswordPolicy],
        ]);
        $ok = (bool) DB::connection('pgsql_auth')->selectOne('select auth_api.consume_password_reset(?, ?) as ok', [$data['token'], $this->passwords->hash($data['password'])])->ok;

        if (! $ok) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired.']);
        }
        if ($this->auth->userId() !== null) {
            $this->auth->end();
        }

        return response()->noContent();
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

    private function workspaceEvent(string $action, string $resource, string $id, int $status, Request $request): SecurityEvent
    {
        $correlation = (string) (Context::get('correlation_id') ?? '');

        return new SecurityEvent(
            $action,
            resource: $resource,
            requestedId: str_replace('-', '', $id),
            route: $request->route()?->getName(),
            httpStatus: $status,
            correlationId: $correlation !== '' ? $correlation : null,
            ipHash: $this->hasher->hash($request->ip()),
            userAgentHash: $this->hasher->hash((string) $request->userAgent()),
        );
    }

    private function authEvent(string $action, ?string $emailHmac, ?string $ipHash, Request $request): void
    {
        $correlation = (string) (Context::get('correlation_id') ?? '');

        DB::connection('pgsql_auth')->select('select auth_api.record_security_event(?, null, ?, ?, ?, ?)', [
            $action, $emailHmac, $ipHash, $this->hasher->hash((string) $request->userAgent()), $correlation !== '' ? $correlation : null,
        ]);
    }
}
