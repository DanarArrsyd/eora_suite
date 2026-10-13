<?php

declare(strict_types=1);

namespace App\Platform\Access\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Access\Http\Resources\MemberResource;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Outbox\OutboxPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administration → Users (doc 28 §1, doc 25 §2.4): invite a member, resend an invitation, suspend and reactivate.
 * Runs inside the `tenant` middleware (one TenantTransaction). Invitation e-mails go out through the durable dispatch
 * request and the delivery worker — nothing is sent from this request. Suspension takes effect on the member's next
 * request (TenantRequest requires an ACTIVE membership).
 */
final class MemberController
{
    private const string INVITE = 'core.users_roles.create';

    private const string MANAGE = 'core.users_roles.post';

    public function __construct(private readonly Authorizer $authorizer, private readonly AuditRecorder $audit, private readonly OutboxPublisher $outbox) {}

    /**
     * Invite by e-mail. The identity is resolved first on the auth connection (`auth_api.invite_identity`: existing
     * e-mail → existing user, otherwise a password-less identity); then the INVITED membership and an INITIAL dispatch
     * request are written in this tenant transaction. Inviting grants no access; roles are assigned separately.
     */
    public function invite(Request $request): JsonResponse
    {
        // same grammar as auth.normalize_email, so the database never rejects what validation accepted
        $email = (string) $request->validate(['email' => ['required', 'string', 'max:254', 'email:rfc', 'regex:/^\s*[^@\s]{1,64}@[^@\s]{1,253}\.[^@\s]{2,63}\s*$/']])['email'];
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::INVITE);

        try {
            $userId = DB::connection('pgsql_auth')->selectOne('select auth_api.invite_identity(?) as id', [$email])->id ?? null;
        } catch (QueryException $e) {
            throw (string) $e->getCode() === '22023' ? ValidationException::withMessages(['email' => 'This e-mail address is not valid.']) : $e;
        }
        if ($userId === null) {
            // platform-wide identity creation cap reached (recorded by the auth function)
            throw new DomainProblem(429, 'RATE_LIMITED', 'Too many requests', 'Invitations are temporarily limited. Please try again later.');
        }

        $existing = $db->selectOne('select status from memberships where user_id = ?', [$userId]);
        if ($existing !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already a member', 'This person is already a member of the tenant (status '.$existing->status.').');
        }
        try {
            $membership = (string) $db->selectOne(
                "insert into memberships (tenant_id, user_id, status) values ((select c.tenant from ctx_api.context() c), ?, 'INVITED') returning id",
                [$userId],
            )->id;
        } catch (QueryException $e) {
            // a concurrent invitation of the same person committed first
            throw (string) $e->getCode() === '23505'
                ? new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already a member', 'This person was just invited by someone else.')
                : $e;
        }
        $this->dispatch($db, $membership, 'INITIAL');
        $this->audit->record($db, 'membership.invited', 'membership', $membership, null, ['status' => 'INVITED', 'userId' => (string) $userId], ['userId' => (string) $userId]);
        // business event only (doc 25 §6.4): delivery uses the dispatch request above, not this event (R-43)
        $this->outbox->publish($db, 'membership.invited', 'membership', $membership, ['membership_id' => $membership, 'user_id' => (string) $userId]);

        return $this->member($db, $membership)->response()->setStatusCode(201);
    }

    /** Resend an open invitation (new secret; the previous link stops working). Capped at 5 per 24 h in the database. */
    public function resendInvitation(string $membership): Response
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::INVITE);
        $row = $this->find($db, $membership);

        if ($row->status !== 'INVITED') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not invited', 'Only a pending invitation can be resent.');
        }
        $this->dispatch($db, (string) $row->id, 'RESEND');
        $this->audit->record($db, 'membership.invitation_resent', 'membership', (string) $row->id);

        return response()->noContent(202);
    }

    /** Suspend an invited or active member: no access in this tenant until reactivated; assignments are kept. */
    public function suspend(string $membership): JsonResponse
    {
        return $this->transition($membership, ['INVITED', 'ACTIVE'], "'SUSPENDED'", 'membership.suspended');
    }

    /** Reactivate a suspended member: back to ACTIVE when it had been activated, otherwise back to INVITED. */
    public function reactivate(string $membership): JsonResponse
    {
        return $this->transition($membership, ['SUSPENDED'], "case when activated_at is null then 'INVITED' else 'ACTIVE' end", 'membership.reactivated');
    }

    /**
     * Suspension and reactivation change what the member can do everywhere it holds a role, so the administrator's
     * `core.users_roles.post` scope must cover every unrevoked assignment of the member (D-068, doc 25 §13), and never
     * its own membership (SoD-06). The member row is locked against concurrent transitions.
     *
     * @param  list<string>  $from
     */
    private function transition(string $membership, array $from, string $to, string $action): JsonResponse
    {
        $db = $this->db();
        $scopes = $this->authorizer->requireAnywhere($db, self::MANAGE, lock: true);
        $row = $this->find($db, $membership, lock: true);
        $this->authorizer->refuseSelf($db, (string) $row->user_id);

        $assignments = $db->select(
            'select ra.scope_type, ra.company_id, ra.plant_id, p.company_id as plant_company_id
               from role_assignments ra left join plants p on p.id = ra.plant_id
              where ra.membership_id = ? and ra.revoked_at is null',
            [$row->id],
        );
        foreach ($assignments as $a) {
            $this->authorizer->requireCovered($scopes, Scope::of((string) $a->scope_type, $a->company_id, $a->plant_id, $a->plant_company_id));
        }
        if (! in_array($row->status, $from, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not allowed in this status', "This action is not possible for a member with status {$row->status}.");
        }
        $status = (string) $db->selectOne("update memberships set status = {$to} where id = ? returning status", [$row->id])->status;
        $this->audit->record($db, $action, 'membership', (string) $row->id, ['status' => $row->status], ['status' => $status], ['from' => $row->status, 'to' => $status]);

        return $this->member($db, (string) $row->id)->response();
    }

    private function dispatch(EoraPostgresConnection $db, string $membership, string $reason): void
    {
        $db->insert(
            'insert into invitation_dispatch_requests (tenant_id, membership_id, reason) values ((select c.tenant from ctx_api.context() c), ?, ?)',
            [$membership, $reason],
        );
    }

    private function find(EoraPostgresConnection $db, string $membership, bool $lock = false): stdClass
    {
        $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $membership) === 1
            ? $db->selectOne('select id, user_id, status from memberships where id = ?'.($lock ? ' for update' : ''), [strtolower($membership)])
            : null;

        return $row ?? throw new NotFoundHttpException;
    }

    private function member(EoraPostgresConnection $db, string $membership): MemberResource
    {
        $m = $db->selectOne(
            'select m.id, m.user_id, m.status, u.display_name, u.email_display from memberships m join users u on u.id = m.user_id where m.id = ?',
            [$membership],
        );
        $m->assignments = $db->select(AccessController::ASSIGNMENT_SQL.' where ra.membership_id = ? and ra.revoked_at is null order by r.code, ra.id', [$membership]);

        return new MemberResource($m);
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Member administration requires the EORA PostgreSQL connection.');
    }
}
