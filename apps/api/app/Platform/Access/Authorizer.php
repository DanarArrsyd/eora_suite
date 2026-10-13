<?php

declare(strict_types=1);

namespace App\Platform\Access;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use stdClass;

/**
 * Permission + scope evaluation (doc 18 §5, doc 25 §3) for the signed actor of the current tenant transaction. The
 * actor is read from ctx_api.context(), never from input. Only assignments that are unrevoked, inside their validity
 * window and held through an ACTIVE membership count. Nothing is cached: a grant or revocation takes effect on the
 * next transaction. No Gate::before shortcut and no platform path (O-007/D-060).
 */
final class Authorizer
{
    /**
     * Scopes in which the actor currently holds $permission. With $lock the underlying assignment rows are locked
     * FOR SHARE until the transaction ends, so a concurrent revocation of the actor's own grant waits for (or deadlocks
     * with) this write instead of both committing (doc 25 §13; e.g. two administrators revoking each other).
     *
     * @return list<Scope>
     */
    public function scopes(EoraPostgresConnection $db, string $permission, bool $lock = false): array
    {
        $rows = $db->select(
            "select ra.scope_type, ra.company_id, ra.plant_id, p.company_id as plant_company_id
               from role_assignments ra
               join memberships m on m.tenant_id = ra.tenant_id and m.id = ra.membership_id and m.status = 'ACTIVE'
                                 and m.user_id = (select c.actor from ctx_api.context() c)
               join role_permissions rp on rp.tenant_id = ra.tenant_id and rp.role_id = ra.role_id and rp.permission_code = ?
               left join plants p on p.tenant_id = ra.tenant_id and p.id = ra.plant_id
              where ra.revoked_at is null and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())".
            ($lock ? ' for share of ra' : ''),
            [$permission],
        );

        return array_values(array_map(static fn (stdClass $r) => Scope::of((string) $r->scope_type, $r->company_id, $r->plant_id, $r->plant_company_id), $rows));
    }

    /**
     * 403 FORBIDDEN when the actor holds the permission nowhere; 403 SCOPE_FORBIDDEN when it holds it, but in no scope
     * covering $target. The response never names the permission or scope (ProblemDetails).
     */
    public function authorize(EoraPostgresConnection $db, string $permission, Scope $target, bool $lock = false): void
    {
        $this->requireCovered($this->requireAnywhere($db, $permission, $lock), $target);
    }

    /**
     * The actor's scopes for $permission, or 403 FORBIDDEN when there is none — checked before anything about the
     * addressed records is looked up, so a caller without the permission learns nothing about them.
     *
     * @return list<Scope>
     */
    public function requireAnywhere(EoraPostgresConnection $db, string $permission, bool $lock = false): array
    {
        $scopes = $this->scopes($db, $permission, $lock);

        return $scopes !== [] ? $scopes : throw new DomainProblem(403, 'FORBIDDEN', 'Forbidden');
    }

    /**
     * Grantor ⊇ target (doc 25 §13): 403 SCOPE_FORBIDDEN unless one of $scopes covers $target.
     *
     * @param  list<Scope>  $scopes
     */
    public function requireCovered(array $scopes, Scope $target): void
    {
        foreach ($scopes as $scope) {
            if ($scope->covers($target)) {
                return;
            }
        }

        throw new DomainProblem(403, 'SCOPE_FORBIDDEN', 'Forbidden');
    }

    /**
     * SoD-06 (D-060): an administrator never changes its own access — grants, revocations, suspension, reactivation.
     * 403 SOD_VIOLATION when $userId is the signed actor.
     */
    public function refuseSelf(EoraPostgresConnection $db, string $userId): void
    {
        if ($db->selectOne('select 1 as self from ctx_api.context() c where c.actor = ?', [$userId]) !== null) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Not allowed on your own access', 'Your own access can be changed only by another administrator.');
        }
    }

    /**
     * Every permission the actor holds anywhere in the active tenant — a UX hint for navigation only; each endpoint
     * still authorizes its own scope.
     *
     * @return list<string>
     */
    public function permissions(EoraPostgresConnection $db): array
    {
        return array_values(array_map(static fn (stdClass $r) => (string) $r->permission_code, $db->select(
            "select distinct rp.permission_code
               from role_assignments ra
               join memberships m on m.tenant_id = ra.tenant_id and m.id = ra.membership_id and m.status = 'ACTIVE'
                                 and m.user_id = (select c.actor from ctx_api.context() c)
               join role_permissions rp on rp.tenant_id = ra.tenant_id and rp.role_id = ra.role_id
              where ra.revoked_at is null and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())
              order by 1",
        )));
    }
}
