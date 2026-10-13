<?php

declare(strict_types=1);

namespace App\Platform\Access\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Access\Http\Resources\MemberResource;
use App\Platform\Access\Http\Resources\RoleAssignmentResource;
use App\Platform\Access\Http\Resources\RoleResource;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Inbox\Notifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administration → Roles & Permissions (doc 28 §1, doc 25 §3/§13, doc 18). Runs inside the `tenant` middleware, i.e.
 * one TenantTransaction with the signed context of the session user. Permission `core.users_roles.*`; SoD-06 and
 * grantor ⊇ grantee (scope) on every grant and revocation. In M1 tenant roles are the template copies and are not
 * editable; only assignments change.
 */
final class AccessController
{
    private const string VIEW = 'core.users_roles.view';

    private const string GRANT = 'core.users_roles.post';

    /** Assignment row + role code + `effective`; shared with MemberController. */
    public const string ASSIGNMENT_SQL = 'select ra.*, r.code as role_code,
            (ra.revoked_at is null and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())) as effective
          from role_assignments ra join roles r on r.id = ra.role_id';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public function roles(): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::VIEW);

        $roles = $db->select(
            "select r.id, r.code, r.name, r.template_code, t.default_scope_type,
                    coalesce((select json_agg(rp.permission_code order by rp.permission_code) from role_permissions rp where rp.role_id = r.id), '[]') as permissions
               from roles r left join role_templates t on t.code = r.template_code
              order by r.code",
        );

        return RoleResource::collection($roles)->response();
    }

    /**
     * Members of the active tenant with their unrevoked assignments (`effective` = inside the validity window now).
     * Assignments are limited to the caller's view scopes as query predicates (doc 18 §5): a company- or plant-scoped
     * administrator sees only assignments inside its company/plant. Member identities are tenant-wide by design (the
     * `users` policy already shows every member of the active tenant, doc 25 §2.2) so a scoped administrator can
     * still pick whom to grant.
     */
    public function members(): JsonResponse
    {
        $db = $this->db();
        $scopes = $this->authorizer->requireAnywhere($db, self::VIEW);

        $members = $db->select(
            'select m.id, m.user_id, m.status, u.display_name, u.email_display
               from memberships m join users u on u.id = m.user_id
              order by u.display_name, m.id',
        );
        [$predicate, $bindings] = $this->scopePredicate($scopes);
        $assignments = [];
        foreach ($db->select(self::ASSIGNMENT_SQL.' left join plants sp on sp.id = ra.plant_id where ra.revoked_at is null and '.$predicate.' order by r.code, ra.id', $bindings) as $a) {
            $assignments[(string) $a->membership_id][] = $a;
        }
        foreach ($members as $m) {
            $m->assignments = $assignments[(string) $m->id] ?? [];
        }

        return MemberResource::collection($members)->response();
    }

    /** Grant a role in a scope to a member (INVITED or ACTIVE). */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membershipId' => ['required', 'uuid'],
            'roleId' => ['required', 'uuid'],
            'scopeType' => ['required', Rule::in([Scope::TENANT, Scope::COMPANY, Scope::PLANT])],
            'companyId' => ['nullable', 'uuid', 'required_if:scopeType,COMPANY', 'prohibited_unless:scopeType,COMPANY'],
            'plantId' => ['nullable', 'uuid', 'required_if:scopeType,PLANT', 'prohibited_unless:scopeType,PLANT'],
            'validUntil' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $db = $this->db();
        // nothing about members, roles or plants is revealed to a caller without the permission
        $grantorScopes = $this->authorizer->requireAnywhere($db, self::GRANT, lock: true);

        $membership = $db->selectOne('select id, user_id, status from memberships where id = ?', [strtolower($data['membershipId'])]);
        if ($membership === null) {
            throw ValidationException::withMessages(['membershipId' => 'Unknown member.']);
        }
        $this->authorizer->refuseSelf($db, (string) $membership->user_id);
        if (! in_array($membership->status, ['INVITED', 'ACTIVE'], true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Member not assignable', 'Roles can be assigned only to invited or active members.');
        }
        if ($db->selectOne('select 1 as ok from roles where id = ?', [strtolower($data['roleId'])]) === null) {
            throw ValidationException::withMessages(['roleId' => 'Unknown role.']);
        }
        $target = $this->targetScope($db, $data['scopeType'], $data['companyId'] ?? null, $data['plantId'] ?? null);
        $this->authorizer->requireCovered($grantorScopes, $target);

        try {
            $row = $db->selectOne(
                'insert into role_assignments (tenant_id, membership_id, role_id, scope_type, company_id, plant_id, valid_until, granted_by, grant_reason)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, (select c.actor from ctx_api.context() c), ?)
                 returning id',
                [strtolower($data['membershipId']), strtolower($data['roleId']), $target->type,
                    $target->type === Scope::COMPANY ? $target->companyId : null, $target->plantId, $data['validUntil'] ?? null, $data['reason']],
            );
        } catch (QueryException $e) {
            $this->rethrow($e, ['23505' => ['roleId' => 'This role is already assigned to the member in this scope.']]);
        }

        $assignment = $db->selectOne(self::ASSIGNMENT_SQL.' where ra.id = ?', [$row->id]);
        // the free-form reason stays on the assignment row only (it may contain anything the administrator typed)
        $event = $this->audit->record($db, 'role_assignment.granted', 'role_assignment', (string) $row->id, null, $this->state($db, (string) $row->id), [
            'membershipId' => (string) $assignment->membership_id, 'roleCode' => (string) $assignment->role_code, 'scopeType' => $target->type,
            'companyId' => $assignment->company_id, 'plantId' => $assignment->plant_id, 'validUntil' => $assignment->valid_until,
        ], $target->plantId);
        $this->notifySensitive($db, $event, $assignment);

        return (new RoleAssignmentResource($assignment))->response()->setStatusCode(201);
    }

    /** Revoke an assignment: kept as history with revoker, time and reason. */
    public function revoke(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $grantorScopes = $this->authorizer->requireAnywhere($db, self::GRANT, lock: true);

        $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $assignment) === 1
            ? $db->selectOne('select ra.*, p.company_id as plant_company_id from role_assignments ra left join plants p on p.id = ra.plant_id where ra.id = ?', [strtolower($assignment)])
            : null;
        if ($row === null) {
            throw new NotFoundHttpException;
        }
        $this->authorizer->refuseSelf($db, (string) $db->selectOne('select user_id from memberships where id = ?', [$row->membership_id])->user_id);
        $this->authorizer->requireCovered($grantorScopes, Scope::of((string) $row->scope_type, $row->company_id, $row->plant_id, $row->plant_company_id));
        if ($row->revoked_at !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already revoked', 'This assignment was already revoked.');
        }

        $before = $this->state($db, (string) $row->id);
        try {
            // conditional: a concurrent revocation that committed first leaves 0 rows here (re-checked after the row lock)
            $updated = $db->update(
                'update role_assignments set revoked_at = now(), revoked_by = (select c.actor from ctx_api.context() c), revoke_reason = ? where id = ? and revoked_at is null',
                [$data['reason'], $row->id],
            );
        } catch (QueryException $e) {
            $this->rethrow($e, []);
        }
        if ($updated === 0) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already revoked', 'This assignment was already revoked.');
        }
        $after = $db->selectOne(self::ASSIGNMENT_SQL.' where ra.id = ?', [$row->id]);
        $this->audit->record($db, 'role_assignment.revoked', 'role_assignment', (string) $row->id, $before, $this->state($db, (string) $row->id), [
            'membershipId' => (string) $after->membership_id, 'roleCode' => (string) $after->role_code,
        ], $after->plant_id);

        return (new RoleAssignmentResource($after))->response();
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Access administration requires the EORA PostgreSQL connection.');
    }

    /**
     * SQL predicate over `ra` (assignment) and `sp` (its plant) matching assignments covered by one of $scopes:
     * tenant covers all, company covers its company and that company's plants, plant covers itself.
     *
     * @param  list<Scope>  $scopes
     * @return array{0: string, 1: list<string>}
     */
    private function scopePredicate(array $scopes): array
    {
        $companies = $plants = [];
        foreach ($scopes as $scope) {
            if ($scope->type === Scope::TENANT) {
                return ['true', []];
            }
            if ($scope->type === Scope::COMPANY) {
                $companies[] = (string) $scope->companyId;
            } else {
                $plants[] = (string) $scope->plantId;
            }
        }
        $array = static fn (array $ids): string => '{'.implode(',', array_unique($ids)).'}';

        return [
            "((ra.scope_type = 'COMPANY' and ra.company_id = any(?::uuid[]))
              or (ra.scope_type = 'PLANT' and (sp.company_id = any(?::uuid[]) or ra.plant_id = any(?::uuid[]))))",
            [$array($companies), $array($companies), $array($plants)],
        ];
    }

    private function targetScope(EoraPostgresConnection $db, string $type, ?string $companyId, ?string $plantId): Scope
    {
        if ($type === Scope::COMPANY) {
            $company = $db->selectOne('select id from companies where id = ?', [strtolower((string) $companyId)]);

            return $company !== null ? Scope::company((string) $company->id) : throw ValidationException::withMessages(['companyId' => 'Unknown company.']);
        }
        if ($type === Scope::PLANT) {
            $plant = $db->selectOne('select id, company_id from plants where id = ?', [strtolower((string) $plantId)]);

            return $plant !== null ? Scope::plant((string) $plant->id, (string) $plant->company_id) : throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        }

        return Scope::tenant();
    }

    /**
     * Maps the database backstops to the API contract: SoD-06 trigger → 403 SOD_VIOLATION; listed SQLSTATEs → 422.
     *
     * @param  array<int|string, array<string, string>>  $validation  SQLSTATE => field errors (numeric states become int keys)
     */
    private function rethrow(QueryException $e, array $validation): never
    {
        $state = (string) $e->getCode();

        if ($state === 'EO006') {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Not allowed on your own access', 'Your own access can be changed only by another administrator.');
        }
        if (isset($validation[$state])) {
            throw ValidationException::withMessages($validation[$state]);
        }

        throw $e;
    }

    /**
     * The audited entity state: the stored row only, the same projection before and after a change.
     *
     * @return array<string, mixed>
     */
    private function state(EoraPostgresConnection $db, string $id): array
    {
        return (array) $db->selectOne('select * from role_assignments where id = ?', [$id]);
    }

    /** D-069: tenant administrators are told when a role carrying sensitive permissions is granted. */
    private function notifySensitive(EoraPostgresConnection $db, string $event, stdClass $assignment): void
    {
        $role = $db->selectOne(
            'select r.code, r.name, exists (select 1 from role_permissions rp join permissions p on p.code = rp.permission_code
                                             where rp.role_id = r.id and p.sensitive) as sensitive
               from roles r where r.id = ?',
            [$assignment->role_id],
        );
        if ($role === null || ! $role->sensitive) {
            return;
        }
        $grantee = $db->selectOne('select u.display_name from memberships m join users u on u.id = m.user_id where m.id = ?', [$assignment->membership_id]);
        $this->notifier->sensitiveRoleGranted($db, $event, (string) $role->code, (string) $role->name, (string) ($grantee->display_name ?? 'a member'), (string) $assignment->scope_type);
    }
}
