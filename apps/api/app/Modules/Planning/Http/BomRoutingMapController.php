<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\BomRoutingMapsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → BOM–routing map (doc 26 §2.5, D-B2-01 revised, D-079): which operation of one routing version consumes
 * each line of one BOM version of the same item. Drafted only once both versions are submitted or approved (their
 * content is locked), approved only when both are approved, by someone who did not prepare or edit the map, in a scope
 * covering the routing's plant (`lifecycle_api.approve_bom_routing_map`). Revisions copy the lines; at most one approved
 * revision per version pair.
 */
final class BomRoutingMapController
{
    use MasterWrites;
    use VersionLifecycle;

    private const string CREATE = 'master.routing.create';

    private const string SUBMIT = 'master.routing.submit';

    private const string APPROVE = 'master.routing.approve';

    private const array LOCKED = ['SUBMITTED', 'APPROVED'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /**
     * A new DRAFT map revision for a (BOM version, routing version) pair of the same item, copying the lines of the
     * pair's latest revision. A pair has at most one open revision (DRAFT, SUBMITTED or REJECTED).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bomVersionId' => ['required', 'uuid'],
            'routingVersionId' => ['required', 'uuid'],
            'changeReason' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $bom = $db->selectOne('select v.id, v.item_id, v.status, v.version_no, i.code as item_code from bom_versions v join items i on i.id = v.item_id where v.id = ?', [strtolower($data['bomVersionId'])])
            ?? throw ValidationException::withMessages(['bomVersionId' => 'Unknown BOM version.']);
        $rv = $db->selectOne(
            'select v.id, v.status, v.routing_id, r.plant_id, r.item_id, i.code as item_code
               from routing_versions v join routings r on r.id = v.routing_id join items i on i.id = r.item_id where v.id = ?',
            [strtolower($data['routingVersionId'])],
        ) ?? throw ValidationException::withMessages(['routingVersionId' => 'Unknown routing version.']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $rv->plant_id));
        if ($bom->item_id !== $rv->item_id) {
            throw ValidationException::withMessages(['routingVersionId' => "The BOM is for {$bom->item_code}, the routing for {$rv->item_code}. Choose a routing of {$bom->item_code}."]);
        }
        if (! in_array($bom->status, self::LOCKED, true) || ! in_array($rv->status, self::LOCKED, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Versions still open', 'Submit the BOM version and the routing version first; a map is drawn on content that no longer changes.');
        }

        $db->selectOne('select id from bom_versions where id = ? for update', [$bom->id]);
        if ($db->selectOne("select 1 as ok from bom_routing_maps where bom_version_id = ? and routing_version_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$bom->id, $rv->id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Open map exists', 'Finish or withdraw the open map of these versions first.');
        }
        $source = $db->selectOne('select id from bom_routing_maps where bom_version_id = ? and routing_version_id = ? order by map_revision desc limit 1', [$bom->id, $rv->id]);
        $revision = (int) $db->selectOne('select coalesce(max(map_revision), 0) + 1 as n from bom_routing_maps where bom_version_id = ? and routing_version_id = ?', [$bom->id, $rv->id])->n;
        $id = (string) $db->selectOne(
            'insert into bom_routing_maps (tenant_id, item_id, plant_id, bom_version_id, routing_id, routing_version_id, map_revision, change_reason)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?) returning id',
            [$bom->item_id, $rv->plant_id, $bom->id, $rv->routing_id, $rv->id, $revision, self::optionalText($data['changeReason'] ?? null)],
        )->id;
        if ($source !== null) {
            $db->insert(
                'insert into bom_routing_map_lines (tenant_id, map_id, bom_version_id, routing_version_id, bom_line_id, routing_operation_id)
                 select tenant_id, ?, bom_version_id, routing_version_id, bom_line_id, routing_operation_id from bom_routing_map_lines where map_id = ?',
                [$id, $source->id],
            );
        }
        $this->audit->record($db, 'bom_routing_map.created', 'bom_routing_map', $id, null, $this->state($db, 'bom_routing_maps', $id), ['mapRevision' => $revision, 'copiedFrom' => $source?->id], (string) $rv->plant_id);

        return $this->respondMap($db, $id, 201);
    }

    /** One map with every BOM line of its version (mapped or not) and the operations of its routing version. */
    public function show(string $map): JsonResponse
    {
        $db = $this->db();
        $scopes = $this->authorizer->requireAnywhere($db, 'master.routing.view');
        $id = $this->existing($db, 'bom_routing_maps', $map);
        $this->authorizer->requireCovered($scopes, $this->plantScope($db, (string) $db->selectOne('select plant_id from bom_routing_maps where id = ?', [$id])->plant_id));

        return $this->respondMap($db, $id);
    }

    /**
     * Replace the lines of a DRAFT map (If-Match on the map): each BOM line of the version at most once, to an operation of
     * the routing version. Several lines may share an operation; the same component on different lines may go to
     * different operations (B2-AT-09/10).
     */
    public function putLines(Request $request, string $map): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.bomLineId' => ['required', 'uuid', 'distinct'],
            'lines.*.routingOperationId' => ['required', 'uuid'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $mp] = $this->locked($map, self::CREATE, 'DRAFT');
        $id = (string) $mp->id;
        foreach ($data['lines'] as $i => $line) {
            if ($db->selectOne('select 1 as ok from bom_lines where id = ? and bom_version_id = ?', [strtolower($line['bomLineId']), $mp->bom_version_id]) === null) {
                throw ValidationException::withMessages(["lines.{$i}.bomLineId" => 'Choose a line of this BOM version.']);
            }
            if ($db->selectOne('select 1 as ok from routing_operations where id = ? and routing_version_id = ?', [strtolower($line['routingOperationId']), $mp->routing_version_id]) === null) {
                throw ValidationException::withMessages(["lines.{$i}.routingOperationId" => 'Choose an operation of this routing version.']);
            }
        }
        if ($db->update('update bom_routing_maps set change_reason = change_reason where id = ? and version = ?', [$id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->delete('delete from bom_routing_map_lines where map_id = ?', [$id]);
        foreach ($data['lines'] as $line) {
            $db->insert(
                'insert into bom_routing_map_lines (tenant_id, map_id, bom_version_id, routing_version_id, bom_line_id, routing_operation_id)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?)',
                [$id, $mp->bom_version_id, $mp->routing_version_id, strtolower($line['bomLineId']), strtolower($line['routingOperationId'])],
            );
        }
        $this->audit->record($db, 'bom_routing_map.lines_replaced', 'bom_routing_map', $id, null, null, ['lineCount' => count($data['lines'])], (string) $mp->plant_id);

        return $this->respondMap($db, $id);
    }

    /** DRAFT → SUBMITTED once every BOM line has its operation. */
    public function submit(string $map): JsonResponse
    {
        [$db, $mp] = $this->locked($map, self::SUBMIT, 'DRAFT');
        $missing = (int) $db->selectOne(
            'select count(*) as n from bom_lines l where l.bom_version_id = ? and not exists (select 1 from bom_routing_map_lines ml where ml.map_id = ? and ml.bom_line_id = l.id)',
            [$mp->bom_version_id, $mp->id],
        )->n;
        if ($missing > 0) {
            throw ValidationException::withMessages(['lines' => $missing === 1 ? 'One BOM line has no operation yet.' : "{$missing} BOM lines have no operation yet."]);
        }
        $this->moveTo($db, 'bom_routing_maps', 'bom_routing_map', $mp, 'SUBMITTED', ['submitted_at' => now()->toIso8601String()], 'bom_routing_map.submitted', (string) $mp->plant_id);

        return $this->respondMap($db, (string) $mp->id);
    }

    public function withdraw(string $map): JsonResponse
    {
        [$db, $mp] = $this->locked($map, self::SUBMIT, 'SUBMITTED');
        $this->moveTo($db, 'bom_routing_maps', 'bom_routing_map', $mp, 'DRAFT', ['submitted_at' => null], 'bom_routing_map.withdrawn', (string) $mp->plant_id);

        return $this->respondMap($db, (string) $mp->id);
    }

    public function reject(Request $request, string $map): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $mp] = $this->locked($map, self::APPROVE, 'SUBMITTED');
        $this->refuseAuthor($db, 'bom_map_contributors', 'map_id', $mp, 'A map is reviewed by someone who did not prepare or edit it.');
        $this->moveTo($db, 'bom_routing_maps', 'bom_routing_map', $mp, 'REJECTED', ['reject_reason' => $data['reason']], 'bom_routing_map.rejected', (string) $mp->plant_id);

        return $this->respondMap($db, (string) $mp->id);
    }

    public function reopen(string $map): JsonResponse
    {
        [$db, $mp] = $this->locked($map, self::SUBMIT, 'REJECTED');
        $this->moveTo($db, 'bom_routing_maps', 'bom_routing_map', $mp, 'DRAFT', ['reject_reason' => null, 'submitted_at' => null], 'bom_routing_map.reopened', (string) $mp->plant_id);

        return $this->respondMap($db, (string) $mp->id);
    }

    /** SUBMITTED → APPROVED through the database function (scope, maker ≠ checker, both versions approved, complete). */
    public function approve(string $map): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::APPROVE);
        $id = $this->existing($db, 'bom_routing_maps', $map);
        try {
            $db->selectOne('select lifecycle_api.approve_bom_routing_map(?) as superseded', [$id]);
        } catch (QueryException $e) {
            throw self::approvalFailure($e, 'A map is approved by someone who did not prepare or edit it.');
        }

        return $this->respondMap($db, $id);
    }

    /**
     * The map locked for a change in `$status`, with the permission in a scope covering the routing's plant.
     *
     * @return array{0: EoraPostgresConnection, 1: stdClass}
     */
    private function locked(string $map, string $permission, string $status): array
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'bom_routing_maps', $map);
        $mp = $db->selectOne('select * from bom_routing_maps where id = ? for update', [$id]);
        $this->authorizer->authorize($db, $permission, $this->plantScope($db, (string) $mp->plant_id));
        self::requireStatus($mp, $status, 'map');

        return [$db, $mp];
    }

    /** The map row with `bomLines` (each with its mapped operation or null) and the routing version's `operations`. */
    private function respondMap(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new BomRoutingMapsQuery, $id) ?? throw new \LogicException('Written row not visible.');
        $row['bomLines'] = array_map(static fn (stdClass $l) => [
            'bomLineId' => $l->id, 'lineNo' => $l->line_no, 'componentCode' => $l->component_code, 'componentName' => $l->component_name,
            'grossQtyPer' => $l->gross_qty_per, 'uomCode' => $l->uom_code, 'linePurpose' => $l->line_purpose, 'routingOperationId' => $l->routing_operation_id,
        ], $db->select(
            'select l.id, l.line_no, i.code as component_code, i.name as component_name, l.gross_qty_per::text as gross_qty_per, u.code as uom_code,
                    l.line_purpose, ml.routing_operation_id
               from bom_routing_maps m
               join bom_lines l on l.bom_version_id = m.bom_version_id
               join items i on i.id = l.component_item_id
               join uoms u on u.id = l.uom_id
               left join bom_routing_map_lines ml on ml.map_id = m.id and ml.bom_line_id = l.id
              where m.id = ? order by l.line_no',
            [$id],
        ));
        $row['operations'] = array_map(static fn (stdClass $o) => [
            'id' => $o->id, 'opSeq' => $o->op_seq, 'stepKey' => $o->step_key, 'processTypeName' => $o->process_type_name, 'workCenterCode' => $o->work_center_code,
        ], $db->select(
            'select o.id, o.op_seq, o.step_key, pt.name as process_type_name, wc.code as work_center_code
               from bom_routing_maps m join routing_operations o on o.routing_version_id = m.routing_version_id
               join process_types pt on pt.id = o.process_type_id join work_centers wc on wc.id = o.work_center_id
              where m.id = ? order by o.op_seq',
            [$id],
        ));

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
