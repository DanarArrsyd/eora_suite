<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Platform\Database\EoraPostgresConnection;
use stdClass;

/**
 * Who decides a document that waits for a second person, and telling them (D-129).
 *
 * Every kind names the permission that decides it, the plant whose scope must cover the decider and the reader (the
 * plant the owning controller authorizes against; null: any scope, e.g. tenant-wide BOMs and conversion data, D-078), the requester, the people who may never decide it (requester, preparer, contributors,
 * D-060), a label and the page to open. Everything runs as the signed-in user under row-level security.
 */
final class Approvals
{
    /** kind => [view permission, decide permission, what it is, select of one document by id] */
    private const array KINDS = [
        'routing_version' => ['master.routing.view', 'master.routing.approve', 'routing version',
            "select v.id, i.code || ' routing ' || r.routing_code || ' v' || v.version_no as label, v.plant_id, v.prepared_by as requester,
                    v.submitted_at as requested_at, '/master/routings?routing=' || r.id || '&version=' || v.id as link,
                    array(select c.actor from routing_version_contributors c where c.routing_version_id = v.id) || array[v.prepared_by] as excluded
               from routing_versions v join routings r on r.id = v.routing_id join items i on i.id = r.item_id where v.id = ?", ],
        'bom_version' => ['master.routing.view', 'master.routing.approve', 'BOM version',
            "select v.id, i.code || ' BOM v' || v.version_no as label, null::uuid as plant_id, v.prepared_by as requester,
                    v.submitted_at as requested_at, '/master/boms?item=' || v.item_id || '&version=' || v.id as link,
                    array(select c.actor from bom_version_contributors c where c.bom_version_id = v.id) || array[v.prepared_by] as excluded
               from bom_versions v join items i on i.id = v.item_id where v.id = ?", ],
        'bom_routing_map' => ['master.routing.view', 'master.routing.approve', 'BOM–routing map',
            "select m.id, i.code || ' BOM–routing map r' || m.map_revision as label, m.plant_id, m.prepared_by as requester,
                    m.submitted_at as requested_at, '/master/boms?item=' || b.item_id || '&version=' || b.id || '&map=' || m.id as link,
                    array(select c.actor from bom_map_contributors c where c.map_id = m.id) || array[m.prepared_by] as excluded
               from bom_routing_maps m join bom_versions b on b.id = m.bom_version_id join items i on i.id = b.item_id where m.id = ?", ],
        'item_weight' => ['master.conversion.view', 'master.conversion.approve', 'net weight',
            "select v.id, i.code || ' net weight v' || v.version_no as label, null::uuid as plant_id, v.prepared_by as requester,
                    v.submitted_at as requested_at, '/master/conversion-data?weight=' || v.item_id || '&version=' || v.id as link,
                    array(select c.actor from item_weight_contributors c where c.item_weight_id = v.id) || array[v.prepared_by] as excluded
               from item_weights v join items i on i.id = v.item_id where v.id = ?", ],
        'conversion_profile_version' => ['master.conversion.view', 'master.conversion.approve', 'conversion profile',
            "select v.id, i.code || ' conversion profile v' || v.version_no as label, null::uuid as plant_id, v.prepared_by as requester,
                    v.submitted_at as requested_at, '/master/conversion-data?profile=' || v.profile_id || '&version=' || v.id as link,
                    array(select c.actor from conversion_profile_version_contributors c where c.profile_version_id = v.id) || array[v.prepared_by] as excluded
               from conversion_profile_versions v join conversion_profiles p on p.id = v.profile_id join items i on i.id = p.item_id where v.id = ?", ],
        'mps_version' => ['planning.mps.view', 'planning.mps.approve', 'MPS',
            "select v.id, v.mps_no as label, v.plant_id, v.submitted_by as requester, v.submitted_at as requested_at,
                    '/ppic/mps?version=' || v.id as link,
                    array(select c.user_id from mps_contributors c where c.mps_version_id = v.id) || array[v.submitted_by] as excluded
               from mps_versions v where v.id = ?", ],
        'work_order' => ['planning.wo.view', 'planning.wo.approve', 'work order release',
            "select w.id, w.wo_no as label, w.plant_id, w.submitted_by as requester, w.submitted_at as requested_at,
                    '/ppic/work-orders?order=' || w.id as link, array[w.submitted_by] as excluded
               from work_orders w where w.id = ?", ],
        'stock_adjustment' => ['inventory.adjustment.view', 'inventory.adjustment.approve', 'stock adjustment',
            "select d.id, coalesce(d.doc_no, 'Adjustment request') as label, d.plant_id, d.requested_by as requester, d.submitted_at as requested_at,
                    '/inventory/adjustments?request=' || d.id as link, array[d.requested_by] as excluded
               from inventory_documents d where d.id = ? and d.doc_type = 'STOCK_ADJUSTMENT'", ],
        'reversal' => ['inventory.reversal.view', 'inventory.reversal.approve', 'reversal',
            "select d.id, coalesce(d.doc_no, 'Reversal request') as label, d.plant_id, d.requested_by as requester, d.submitted_at as requested_at,
                    '/inventory/reversals?request=' || d.id as link, array[d.requested_by] as excluded
               from inventory_documents d where d.id = ? and d.doc_type = 'REVERSAL'", ],
        'quality_release' => ['quality.disposition_release.view', 'quality.disposition_release.approve', 'QC release',
            "select d.id, coalesce(d.doc_no, 'QC release request') as label, d.plant_id, d.requested_by as requester, d.submitted_at as requested_at,
                    '/quality/inspection-lots?request=' || d.id as link, array[d.requested_by] as excluded
               from inventory_documents d where d.id = ? and d.doc_type = 'QC_DISPOSITION'", ],
        'wo_close_exception' => ['planning.wo_close_exception.view', 'planning.wo_close_exception.approve', 'work order close exception',
            "select d.id, coalesce(d.doc_no, 'WO close exception') as label, d.plant_id, d.requested_by as requester, d.submitted_at as requested_at,
                    '/ppic/wo-close-exceptions' as link, array[d.requested_by] as excluded
               from inventory_documents d where d.id = ? and d.doc_type = 'WO_CLOSE_EXCEPTION'", ],
        'period_close' => ['inventory.period_close.view', 'inventory.period_close.approve', 'month close',
            "select p.id, pl.code || ' ' || to_char(p.period, 'YYYY-MM') || ' close' as label, p.plant_id, p.close_requested_by as requester,
                    p.close_requested_at as requested_at, '/inventory/period-close?period=' || p.id as link, array[p.close_requested_by] as excluded
               from inventory_periods p join plants pl on pl.id = p.plant_id where p.id = ?", ],
        'period_reopen' => ['inventory.period_close.view', 'inventory.period_reopen.approve', 'month reopen',
            "select p.id, pl.code || ' ' || to_char(p.period, 'YYYY-MM') || ' reopen' as label, p.plant_id, p.reopen_requested_by as requester,
                    p.reopen_requested_at as requested_at, '/inventory/period-close?period=' || p.id as link, array[p.reopen_requested_by] as excluded
               from inventory_periods p join plants pl on pl.id = p.plant_id where p.id = ?", ],
    ];

    /** The inventory document kinds by their doc_type. */
    public const array DOCUMENT_KINDS = [
        'STOCK_ADJUSTMENT' => 'stock_adjustment', 'REVERSAL' => 'reversal', 'QC_DISPOSITION' => 'quality_release', 'WO_CLOSE_EXCEPTION' => 'wo_close_exception',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public static function viewPermission(string $kind): string
    {
        return self::KINDS[$kind][0];
    }

    /**
     * What the screen shows: whether the document waits, who asked, and who may decide it (role names, people).
     *
     * @return array{waiting: bool, requestedBy: ?string, requestedAt: ?string, roles: list<array{code: string, name: string}>, approvers: list<array{name: string, roles: list<string>}>}
     */
    public function status(EoraPostgresConnection $db, string $kind, string $id): array
    {
        $doc = $this->document($db, $kind, $id);
        $waiting = $doc !== null && $this->waiting($db, $kind, $id);
        $people = $waiting ? $this->deciders($db, $kind, $doc) : [];
        $roles = $db->select(
            'select distinct r.code, r.name from roles r join role_permissions rp on rp.role_id = r.id and rp.permission_code = ?
              where r.template_code is distinct from ? order by r.name',
            [self::KINDS[$kind][1], 'SUP'],
        );
        $byPerson = [];
        foreach ($people as $p) {
            $byPerson[(string) $p->user_id]['name'] = (string) $p->display_name;
            $byPerson[(string) $p->user_id]['roles'][] = (string) $p->role_name;
        }
        $requester = $doc?->requester !== null
            ? $db->selectOne('select display_name from users where id = ?', [$doc->requester])?->display_name
            : null;

        return [
            'waiting' => $waiting,
            'requestedBy' => $requester !== null ? (string) $requester : null,
            'requestedAt' => $doc?->requested_at !== null ? (string) $doc->requested_at : null,
            'roles' => array_values(array_map(static fn (stdClass $r) => ['code' => (string) $r->code, 'name' => (string) $r->name], $roles)),
            'approvers' => array_values(array_map(static fn (array $p) => ['name' => $p['name'], 'roles' => array_values(array_unique($p['roles']))], $byPerson)),
        ];
    }

    /**
     * Whether the document exists in the tenant, and its plant (null for tenant-wide kinds), so the caller is checked
     * against the plant before anything about it is shown.
     *
     * @return array{0: bool, 1: ?string}
     */
    public function locate(EoraPostgresConnection $db, string $kind, string $id): array
    {
        $doc = $this->document($db, $kind, $id);

        return [$doc !== null, $doc?->plant_id !== null ? (string) $doc->plant_id : null];
    }

    /** Tells everyone who may decide the document that it waits for them (one inbox row each; action required while it waits). */
    public function requested(EoraPostgresConnection $db, string $kind, string $id): int
    {
        $doc = $this->document($db, $kind, $id);
        if ($doc === null) {
            return 0;
        }
        $actor = ($db->selectOne('select u.display_name from users u where u.id = (select c.actor from ctx_api.context() c)')->display_name ?? 'Someone');
        $event = (string) $db->selectOne('select uuidv7() as id')->id;
        $what = self::KINDS[$kind][2];
        $sent = 0;
        foreach ($this->recipients($this->deciders($db, $kind, $doc)) as $membership => $user) {
            $sent += $this->send($db, $membership, $user, $event, 'approval.requested', 'INFO', true,
                "Approval requested: {$doc->label}", "{$actor} asks you to approve this {$what}.", (string) $doc->link, $kind, $id);
        }

        return $sent;
    }

    /** Tells the requester what was decided ($outcome: approved, returned or rejected; with the reason when given). */
    public function decided(EoraPostgresConnection $db, string $kind, string $id, string $outcome, ?string $reason = null): int
    {
        $doc = $this->document($db, $kind, $id);
        if ($doc === null || $doc->requester === null) {
            return 0;
        }
        $member = $db->selectOne("select m.id from memberships m where m.user_id = ? and m.status = 'ACTIVE'", [$doc->requester]);
        $actor = (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
        if ($member === null || $actor === (string) $doc->requester) {
            return 0;
        }
        $by = ($db->selectOne('select display_name from users where id = ?', [$actor])->display_name ?? 'Someone');
        $event = (string) $db->selectOne('select uuidv7() as id')->id;
        $title = ucfirst($outcome).": {$doc->label}";
        $body = "{$by} {$outcome} your ".self::KINDS[$kind][2].'.'.($reason !== null && $reason !== '' ? " Reason: {$reason}" : '');

        return $this->send($db, (string) $member->id, (string) $doc->requester, $event, "approval.{$outcome}", $outcome === 'approved' ? 'INFO' : 'WARNING', false,
            $title, mb_substr($body, 0, 1000), (string) $doc->link, null, null);
    }

    private function document(EoraPostgresConnection $db, string $kind, string $id): ?stdClass
    {
        return $db->selectOne(self::KINDS[$kind][3], [strtolower($id)]);
    }

    private function waiting(EoraPostgresConnection $db, string $kind, string $id): bool
    {
        $subject = in_array($kind, self::DOCUMENT_KINDS, true) ? 'inventory_document' : $kind;

        return (bool) $db->selectOne('select eora_approval_waiting(?, ?::uuid) as w', [$subject, strtolower($id)])->w;
    }

    /**
     * Active members holding the deciding permission in a scope covering the document's plant (any scope when it has
     * none), never the requester, preparer or a contributor.
     *
     * @return list<stdClass> one row per member and role: user_id, membership_id, display_name, role_name
     */
    private function deciders(EoraPostgresConnection $db, string $kind, stdClass $doc): array
    {
        return array_values($db->select(
            "select distinct m.user_id, m.id as membership_id, u.display_name, r.name as role_name
               from memberships m
               join users u on u.id = m.user_id
               join role_assignments ra on ra.membership_id = m.id and ra.revoked_at is null and ra.valid_from <= now()
                                       and (ra.valid_until is null or ra.valid_until > now())
               join roles r on r.id = ra.role_id
               join role_permissions rp on rp.role_id = ra.role_id and rp.permission_code = ?
              where m.status = 'ACTIVE'
                and (?::uuid is null or ra.scope_type = 'TENANT'
                     or (ra.scope_type = 'PLANT' and ra.plant_id = ?::uuid)
                     or (ra.scope_type = 'COMPANY' and ra.company_id = (select p.company_id from plants p where p.id = ?::uuid)))
                and not (m.user_id = any(array_remove(coalesce(?::uuid[], '{}'), null)))
              order by u.display_name, r.name",
            [self::KINDS[$kind][1], $doc->plant_id, $doc->plant_id, $doc->plant_id, $doc->excluded === null ? null : (string) $doc->excluded],
        ));
    }

    /**
     * @param  list<stdClass>  $people
     * @return array<string, string> membership => user
     */
    private function recipients(array $people): array
    {
        $out = [];
        foreach ($people as $p) {
            $out[(string) $p->membership_id] = (string) $p->user_id;
        }

        return $out;
    }

    private function send(EoraPostgresConnection $db, string $membership, string $user, string $event, string $kind, string $severity, bool $action,
        string $title, string $body, string $link, ?string $subjectKind, ?string $subjectId): int
    {
        if ($subjectKind !== null && in_array($subjectKind, self::DOCUMENT_KINDS, true)) {
            $subjectKind = 'inventory_document';
        }

        return $db->affectingStatement(
            'insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, action_required, title, body, deep_link, subject_kind, subject_id)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?::uuid, ?, ?, ?, ?, ?, ?, ?, ?::uuid)',
            [$membership, $user, $event, $kind, $severity, $action, mb_substr($title, 0, 200), $body, $link, $subjectKind, $subjectId],
        );
    }
}
