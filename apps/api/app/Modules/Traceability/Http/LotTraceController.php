<?php

declare(strict_types=1);

namespace App\Modules\Traceability\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Traceability → lot trace (doc 33 §1.9, D-118, doc 14 #18), read from the ledger and its genealogy only.
 *
 * Backward, a shipment or a lot goes back through every lot it was made or split from, to the material lots received from
 * suppliers; forward, a lot goes to every lot made from it and every shipment that carried it (recall). Each lot shows
 * how it was made, the quantity used, who posted it and when; a lot's shipments show the customer and the SO line.
 * Permission `traceability.lot_trace.view` (CSA, INV, AUD); documents of plants the viewer does not hold it in are shown
 * only as outside the viewer's plants, and the trace does not continue through them.
 */
final class LotTraceController
{
    /** Genealogy is followed this many levels deep at most. */
    public const int MAX_DEPTH = 30;

    /** A trace lists this many lots at most; a longer one is cut and says so. */
    public const int MAX_NODES = 2000;

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Trace a lot number or a shipment number (`SHP-`). A lot number shared by several items answers with the matches to
     * choose from (`lotId` picks one). A shipment is traced backward only.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:60'],
            'direction' => ['required', 'string', 'in:backward,forward'],
            'lotId' => ['nullable', 'uuid'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'traceability.lot_trace.view');
        $plants = array_map(static fn (stdClass $p) => (string) $p->id,
            $db->select("select id from plants where public.eora_actor_may('traceability.lot_trace.view', id) order by code"));
        $plantList = '{'.implode(',', $plants).'}';
        $q = strtoupper(trim($data['q']));
        $backward = $data['direction'] === 'backward';

        $shipment = null;
        if (str_starts_with($q, 'SHP-')) {
            if (! $backward) {
                throw ValidationException::withMessages(['direction' => 'A shipment is traced backward. Trace one of its lots forward instead.']);
            }
            $shipment = $db->selectOne("select d.id, d.doc_no from inventory_documents d where d.doc_type = 'SHIPMENT' and d.doc_no = ? and d.plant_id = any(?::uuid[])",
                [$q, $plantList]) ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found', "No shipment {$q} in your plants.");
            $roots = array_map(static fn (stdClass $r) => (string) $r->lot_id, $db->select(
                "select distinct m.lot_id from inventory_movements m join locations l on l.id = m.location_id and l.location_type = 'V_CUSTOMER'
                  where m.document_id = ? and m.qty_signed > 0 and m.lot_id is not null order by m.lot_id", [$shipment->id]));
        } else {
            // a lot is found when the viewer's plants hold one of its movements
            $matches = $db->select(
                'select l.id, l.lot_no, i.code as item_code, i.name as item_name from lots l join items i on i.id = l.item_id
                  where upper(l.lot_no) = ? and exists (select 1 from inventory_movements m where m.lot_id = l.id and m.plant_id = any(?::uuid[]))
                  order by i.code',
                [$q, $plantList],
            );
            if (isset($data['lotId'])) {
                $matches = array_values(array_filter($matches, static fn (stdClass $m) => $m->id === strtolower($data['lotId'])));
            }
            if ($matches === []) {
                throw new DomainProblem(404, 'NOT_FOUND', 'Not found', "No lot or shipment {$q} in your plants.");
            }
            if (count($matches) > 1) {
                return response()->json(['data' => ['direction' => $data['direction'], 'matches' => array_map(static fn (stdClass $m) => [
                    'lotId' => (string) $m->id, 'lotNo' => (string) $m->lot_no, 'itemCode' => (string) $m->item_code, 'itemName' => (string) $m->item_name,
                ], $matches)]]);
            }
            $roots = [(string) $matches[0]->id];
        }

        $roots = array_values($roots);
        [$nodes, $truncated] = $this->tree($db, $roots, $backward, $plantList);
        /** @var list<string> $reached */
        $reached = array_values(array_unique(array_map('strval', array_column($nodes, 'lotId'))));
        $lots = $this->lots($db, $reached, $plantList);
        // a lot's shipments: backward only at the start (the shipment searched, when it was one); forward at every lot
        $shipments = $this->shipments($db, $backward ? $roots : $reached, $plantList, $shipment?->id);

        $rows = [];
        foreach ($nodes as $n) {
            $lot = $lots[$n['lotId']];
            $isRoot = $n['depth'] === 0;
            $rows[] = $n + [
                'lotNo' => $lot['lotNo'], 'itemCode' => $lot['itemCode'], 'itemName' => $lot['itemName'], 'uomCode' => $lot['uomCode'],
                'origin' => $lot['origin'], 'supplierLotNo' => $lot['supplierLotNo'],
                'receipt' => $n['restricted'] ? null : $lot['receipt'],
                'shipments' => $n['restricted'] || ($backward && ! $isRoot) ? [] : ($shipments[$n['lotId']] ?? []),
            ];
        }

        return response()->json(['data' => [
            'direction' => $data['direction'],
            'start' => $shipment !== null ? ['kind' => 'shipment', 'docNo' => (string) $shipment->doc_no] : ['kind' => 'lot', 'lotNo' => $rows[0]['lotNo'], 'itemCode' => $rows[0]['itemCode']],
            'nodes' => $rows,
            'truncated' => $truncated,
        ]]);
    }

    /**
     * The genealogy from the roots, depth first: one row per lot reached by a posted (or reversed) document, with how it
     * was reached. A path never visits a lot twice; a document outside the viewer's plants ends its path.
     *
     * @param  list<string>  $roots
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function tree(EoraPostgresConnection $db, array $roots, bool $backward, string $plants): array
    {
        [$here, $next] = $backward ? ['to_lot_id', 'from_lot_id'] : ['from_lot_id', 'to_lot_id'];
        $edges = $db->select(
            "with recursive t (lot_id, edge_id, depth, path, steps, visible) as (
                 select r, null::uuid, 0, array[r], array[r], true from unnest(?::uuid[]) r
                 union all
                 select g.{$next}, g.id, t.depth + 1, t.path || g.{$next}, t.steps || g.id, d.plant_id = any(?::uuid[])
                   from t join genealogy_edges g on g.{$here} = t.lot_id
                   join inventory_documents d on d.id = g.document_id and d.status in ('POSTED', 'REVERSED')
                  where t.visible and t.depth < ? and not g.{$next} = any(t.path)
             )
             select t.lot_id, t.edge_id, t.depth, t.steps, t.visible, d.doc_no, d.doc_type, d.status as doc_status,
                    d.posted_at, up.display_name as posted_by_name, r.run_no, w.wo_no,
                    trim_scale(g.qty_from)::text as qty_from, uf.code as uom_from, trim_scale(g.qty_to)::text as qty_to, ut.code as uom_to
               from t
               left join genealogy_edges g on g.id = t.edge_id
               left join inventory_documents d on d.id = g.document_id
               left join users up on up.id = d.posted_by
               left join production_runs r on r.id = coalesce(g.run_id, case when d.source_type = 'production_run' then d.source_id end)
               left join work_orders w on w.id = r.work_order_id
               left join uoms uf on uf.id = g.uom_from
               left join uoms ut on ut.id = g.uom_to
              limit ?",
            ['{'.implode(',', $roots).'}', $plants, self::MAX_DEPTH, self::MAX_NODES * 4 + 1],
        );

        // children of each row, by its steps (root lot, then the edges taken): a lot can be reached by several paths
        $children = [];
        foreach ($edges as $e) {
            $children[$e->depth === 0 ? '' : $this->parentPath((string) $e->steps)][] = $e;
        }
        $nodes = [];
        $truncated = count($edges) > self::MAX_NODES * 4;
        $walk = function (string $parentPath, ?string $parentKey) use (&$walk, &$nodes, &$truncated, $children): void {
            $rows = $children[$parentPath] ?? [];
            usort($rows, static fn (stdClass $a, stdClass $b) => [(string) $a->lot_id, (string) $a->edge_id] <=> [(string) $b->lot_id, (string) $b->edge_id]);
            foreach ($rows as $e) {
                if (count($nodes) >= self::MAX_NODES) {
                    $truncated = true;

                    return;
                }
                $key = (string) $e->steps;
                $restricted = ! $e->visible;
                $nodes[] = [
                    'key' => $key, 'parentKey' => $parentKey, 'depth' => (int) $e->depth, 'lotId' => (string) $e->lot_id, 'restricted' => $restricted,
                    'via' => $e->edge_id === null ? null : ($restricted ? ['outsideYourPlants' => true] : [
                        'docNo' => $e->doc_no, 'docType' => (string) $e->doc_type, 'reversed' => $e->doc_status === 'REVERSED',
                        'runNo' => $e->run_no, 'workOrderNo' => $e->wo_no, 'postedByName' => $e->posted_by_name, 'postedAt' => $e->posted_at,
                        // backward: the input used and the output it went into; forward the same edge, read from the input
                        'qtyUsed' => (string) $e->qty_from, 'uomUsed' => (string) $e->uom_from, 'qtyMade' => (string) $e->qty_to, 'uomMade' => (string) $e->uom_to,
                    ]),
                ];
                if (! $restricted) {
                    $walk($key, $key);
                }
            }
        };
        $walk('', null);

        return [$nodes, $truncated];
    }

    /** "{a,b,c}" → "{a,b}" (the path of the parent row). */
    private function parentPath(string $path): string
    {
        $ids = explode(',', trim($path, '{}'));
        array_pop($ids);

        return '{'.implode(',', $ids).'}';
    }

    /**
     * Each lot with its item and, for a received lot, its receipt.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function lots(EoraPostgresConnection $db, array $ids, string $plants): array
    {
        $rows = $db->select(
            "select l.id, l.lot_no, l.origin, l.supplier_lot_no, i.code as item_code, i.name as item_name, u.code as uom,
                    rc.doc_no as receipt_no, rc.posted_at as received_at, rc.received_by, rc.supplier_code, rc.supplier_name
               from lots l join items i on i.id = l.item_id join uoms u on u.id = i.base_uom_id
               left join lateral (
                    select d.doc_no, d.posted_at, up.display_name as received_by, s.code as supplier_code, s.name as supplier_name
                      from inventory_movements m join inventory_documents d on d.id = m.document_id and d.plant_id = any(?::uuid[])
                      left join users up on up.id = d.posted_by
                      left join suppliers s on d.source_type = 'supplier' and s.id = d.source_id
                     where m.lot_id = l.id and m.movement_code = 'RCPT_SUPPLIER' and m.qty_signed > 0
                     order by d.posted_at limit 1) rc on l.origin = 'RECEIPT'
              where l.id = any(?::uuid[])",
            [$plants, '{'.implode(',', $ids).'}'],
        );
        $lots = [];
        foreach ($rows as $r) {
            $lots[(string) $r->id] = [
                'lotNo' => (string) $r->lot_no, 'itemCode' => (string) $r->item_code, 'itemName' => (string) $r->item_name, 'uomCode' => (string) $r->uom,
                'origin' => (string) $r->origin, 'supplierLotNo' => $r->supplier_lot_no,
                'receipt' => $r->receipt_no === null ? null : [
                    'docNo' => (string) $r->receipt_no, 'supplierCode' => $r->supplier_code, 'supplierName' => $r->supplier_name,
                    'postedByName' => $r->received_by, 'postedAt' => $r->received_at,
                ],
            ];
        }

        return $lots;
    }

    /**
     * What was shipped of each lot, per shipment and schedule line (net of a reversal), in the viewer's plants.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function shipments(EoraPostgresConnection $db, array $ids, string $plants, ?string $only): array
    {
        $rows = $db->select(
            "select m.lot_id, d.doc_no, d.status, d.posted_at, up.display_name as posted_by_name, c.code as customer_code, c.name as customer_name,
                    h.so_no, ol.line_no, s.schedule_no, ol.customer_po_no, trim_scale(sum(m.qty_signed))::text as qty, u.code as uom
               from inventory_movements m
               join locations l on l.id = m.location_id and l.location_type = 'V_CUSTOMER'
               join inventory_documents d on d.id = m.document_id and d.doc_type = 'SHIPMENT' and d.plant_id = any(?::uuid[])
               left join users up on up.id = d.posted_by
               join delivery_schedule_lines s on s.id = m.schedule_line_id
               join sales_order_lines ol on ol.id = s.so_line_id join sales_order_headers h on h.id = ol.sales_order_id
               join customers c on c.id = ol.customer_id
               join items i on i.id = m.item_id join uoms u on u.id = i.base_uom_id
              where m.lot_id = any(?::uuid[]) and m.qty_signed > 0 and (?::uuid is null or d.id = ?::uuid)
              group by m.lot_id, d.id, d.doc_no, d.status, d.posted_at, up.display_name, c.code, c.name, h.so_no, ol.line_no, s.schedule_no, ol.customer_po_no, u.code
              order by d.posted_at, d.doc_no, h.so_no, ol.line_no, s.schedule_no",
            [$plants, '{'.implode(',', $ids).'}', $only, $only],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->lot_id][] = [
                'docNo' => (string) $r->doc_no, 'reversed' => $r->status === 'REVERSED', 'postedByName' => $r->posted_by_name, 'postedAt' => $r->posted_at,
                'customerCode' => (string) $r->customer_code, 'customerName' => (string) $r->customer_name, 'customerPoNo' => $r->customer_po_no,
                'soNo' => (string) $r->so_no, 'lineNo' => (int) $r->line_no, 'scheduleNo' => (int) $r->schedule_no, 'qty' => (string) $r->qty, 'uomCode' => (string) $r->uom,
            ];
        }

        return $out;
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('The lot trace requires the EORA PostgreSQL connection.');
    }
}
