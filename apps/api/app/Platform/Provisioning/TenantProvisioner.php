<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use App\Platform\Access\RoleTemplates;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tenant provisioning without bypassing RLS (doc 25 §2.5, D-B1-13, D-B1-16): idempotent steps, no external I/O
 * inside a transaction.
 *  1. identity: auth_api.provision_identity(email) (new or existing user; a DISABLED identity is refused here, before
 *     anything binds the tenant code to it);
 *  2. registry: prov_api.begin_provisioning(request_key, code, admin e-mail HMAC) — same inputs → same tenant id;
 *  3. tenant transaction as eora_provisioner in the new tenant's signed context: lock_request → tenant, company, plant,
 *     tenant-default business calendar, roles from templates, INVITED membership, dispatch request, TENANT_ADMIN at
 *     tenant scope, tenant ACTIVE → mark_tenant_created; all or nothing.
 * A re-run after success touches no tenant rows and only queues a resend for the stored first administrator.
 * Delivery happens afterwards, by the worker (e-mail) or by the operator's command (console).
 */
final class TenantProvisioner
{
    /** Actor stamped on everything provisioning writes (created_by, granted_by): never a user id. */
    public const string SYSTEM_ACTOR = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly SecurityHasher $hasher,
        private readonly RoleTemplates $roles,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  Closure(EoraPostgresConnection): void|null  $beforeCommit  test hook (crash injection, T-PROV-13)
     */
    public function provision(TenantSpec $spec, bool $console = false, ?Closure $beforeCommit = null): ProvisioningResult
    {
        $email = mb_strtolower(trim($spec->adminEmail));
        $key = (string) $this->hasher->hash($spec->tenantCode."\n".$email);
        $db = $this->db();

        try {
            $user = $db->selectOne('select auth_api.provision_identity(?) as id', [$email])->id ?? null;
        } catch (QueryException $e) {
            throw (string) $e->getCode() === 'EA001' ? new ProvisioningException('IDENTITY_NOT_ACTIVE', 'This e-mail belongs to a disabled account; choose another administrator.') : $e;
        }
        if ($user === null) {
            throw new ProvisioningException('IDENTITY_CAPPED', 'Identity creation is temporarily capped; retry later with the same inputs.');
        }

        try {
            $registry = $db->selectOne('select * from prov_api.begin_provisioning(?, ?, ?)', [$key, $spec->tenantCode, $this->hasher->hash($email)]);
        } catch (QueryException $e) {
            throw (string) $e->getCode() === 'EP001' ? new ProvisioningException('TENANT_CODE_TAKEN', 'The tenant code is already used by another provisioning request.') : $e;
        }
        $tenantId = (string) $registry->tenant_id;

        if ($registry->status === 'STARTED') {
            try {
                $membership = $this->createTenant($spec, $key, $tenantId, (string) $user, $console, $beforeCommit);

                return new ProvisioningResult($tenantId, $membership, created: true);
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== 'EP002') {
                    throw $e;
                }
                // a concurrent run committed first: continue with the resend path
                $registry = $db->selectOne('select * from prov_api.provision_lookup(?, ?)', [$spec->tenantCode, $key]);
            }
        }

        if ($registry->admin_membership_id === null) {
            throw new RuntimeException('Provisioning registry is not in a resumable state ('.$registry->status.').');
        }
        $membership = (string) $registry->admin_membership_id;
        $this->inTenant($tenantId, function (EoraPostgresConnection $db) use ($tenantId, $membership, $console): void {
            $this->dispatch($db, $tenantId, $membership, $console ? 'CONSOLE' : 'RESEND');
            $this->audit->record($db, 'membership.invitation_resent', 'membership', $membership, detail: ['via' => 'provisioning', 'delivery' => $console ? 'CONSOLE' : 'EMAIL']);
        });

        return new ProvisioningResult($tenantId, $membership, created: false);
    }

    /** @param  Closure(EoraPostgresConnection): void|null  $beforeCommit */
    private function createTenant(TenantSpec $spec, string $key, string $tenantId, string $user, bool $console, ?Closure $beforeCommit): string
    {
        return $this->inTenant($tenantId, function (EoraPostgresConnection $db) use ($spec, $key, $tenantId, $user, $console, $beforeCommit): string {
            $db->selectOne('select prov_api.lock_request(?) as id', [$key]);
            $db->insert('insert into tenants (id, code, name, timezone) values (?, ?, ?, ?)', [$tenantId, $spec->tenantCode, $spec->tenantName, $spec->timezone]);
            $company = (string) $db->selectOne('insert into companies (tenant_id, code, legal_name) values (?, ?, ?) returning id', [$tenantId, $spec->companyCode, $spec->companyName])->id;
            $db->insert('insert into plants (tenant_id, company_id, code, name, timezone) values (?, ?, ?, ?, ?)', [$tenantId, $company, $spec->plantCode, $spec->plantName, $spec->timezone]);
            // tenant default calendar (ADR-0012, D-044): ISO week, tenant time zone, operator-given production-day cut-off
            $db->insert(
                'insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, ?, ?, ?)',
                [$tenantId, $spec->timezone, $spec->productionDayStartsAt, Date::now($spec->timezone)->toDateString()],
            );
            $this->roles->instantiate($db, $tenantId);

            $membership = (string) $db->selectOne("insert into memberships (tenant_id, user_id, status) values (?, ?, 'INVITED') returning id", [$tenantId, $user])->id;
            $this->dispatch($db, $tenantId, $membership, $console ? 'CONSOLE' : 'INITIAL');
            $db->insert(
                "insert into role_assignments (tenant_id, membership_id, role_id, scope_type, granted_by, grant_reason)
                 select ?, ?, r.id, 'TENANT', ?, 'Tenant provisioning: first administrator' from roles r where r.code = 'TENANT_ADMIN'",
                [$tenantId, $membership, self::SYSTEM_ACTOR],
            );
            $db->update("update tenants set status = 'ACTIVE' where id = ?", [$tenantId]);
            $this->audit->record($db, 'tenant.provisioned', 'tenant', $tenantId, null, ['code' => $spec->tenantCode, 'status' => 'ACTIVE'], [
                'tenantCode' => $spec->tenantCode, 'companyCode' => $spec->companyCode, 'plantCode' => $spec->plantCode,
                'adminMembershipId' => $membership, 'delivery' => $console ? 'CONSOLE' : 'EMAIL',
            ]);
            $db->select('select prov_api.mark_tenant_created(?, ?, ?)', [$key, $user, $membership]);

            if ($beforeCommit !== null) {
                $beforeCommit($db);
            }

            return $membership;
        });
    }

    private function dispatch(EoraPostgresConnection $db, string $tenantId, string $membership, string $reason): void
    {
        $db->insert('insert into invitation_dispatch_requests (tenant_id, membership_id, reason) values (?, ?, ?)', [$tenantId, $membership, $reason]);
    }

    /**
     * @template T
     *
     * @param  Closure(EoraPostgresConnection): T  $work
     * @return T
     */
    private function inTenant(string $tenantId, Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::forTenant($tenantId, self::SYSTEM_ACTOR), $work, 'pgsql_provisioner');
    }

    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_provisioner');
    }
}
