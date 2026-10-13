<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Access\RoleTemplates;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;

/**
 * Tenant roles and assignments the way provisioning will create them (doc 25 §2.5): as eora_provisioner in the
 * tenant's own signed context with the system actor, never as owner or superuser.
 */
final class AccessFixtures
{
    public static function roles(array $tenant): void
    {
        self::run($tenant, fn (EoraPostgresConnection $db) => app(RoleTemplates::class)->instantiate($db, $tenant['tenant']));
    }

    /**
     * Grants $roleCode to the member with $userId. $scope: ['TENANT'], ['COMPANY', companyId] or ['PLANT', plantId].
     *
     * @param  array{0: string, 1?: string}  $scope
     */
    public static function grant(array $tenant, string $userId, string $roleCode, array $scope = ['TENANT'], ?string $validFrom = null, ?string $validUntil = null): string
    {
        return self::run($tenant, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
            "insert into role_assignments (tenant_id, membership_id, role_id, scope_type, company_id, plant_id, valid_from, valid_until, granted_by, grant_reason)
             select ?, m.id, r.id, ?, ?, ?, coalesce(?::timestamptz, now()), ?, ?, 'fixture'
               from memberships m, roles r where m.user_id = ? and r.code = ?
             returning id",
            [$tenant['tenant'], $scope[0], $scope[0] === 'COMPANY' ? $scope[1] : null, $scope[0] === 'PLANT' ? $scope[1] : null,
                $validFrom, $validUntil, $tenant['actor'], $userId, $roleCode],
        )->id);
    }

    public static function userId(array $tenant, string $email): string
    {
        return (string) self::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select id from users where email_display = ?', [$email])->id);
    }

    public static function membershipId(array $tenant, string $userId): string
    {
        return (string) self::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select id from memberships where user_id = ?', [$userId])->id);
    }

    public static function revoke(array $tenant, string $assignmentId): void
    {
        self::run($tenant, fn (EoraPostgresConnection $db) => $db->update(
            "update role_assignments set revoked_at = now(), revoked_by = ?, revoke_reason = 'fixture' where id = ?", [$tenant['actor'], $assignmentId],
        ));
    }

    /**
     * @template T
     *
     * @param  \Closure(EoraPostgresConnection): T  $work
     * @return T
     */
    public static function run(array $tenant, \Closure $work): mixed
    {
        return app(TenantTransaction::class)->run(TenantContext::forTenant($tenant['tenant'], $tenant['actor']), $work, 'pgsql_provisioner');
    }
}
