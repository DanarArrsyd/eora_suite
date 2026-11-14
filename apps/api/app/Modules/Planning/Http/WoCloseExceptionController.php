<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Http\PostingRequests;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * PPIC → work order close exception (doc 33 §1.8, D-063, D-117, BR-PLN-03, M4-D1).
 *
 * Material left at production that will not be returned is closed by an exception: a planner of the plant asks for it
 * with the reason (`planning.wo_close_exception.request_correction`); CSA or INV decides it
 * (`planning.wo_close_exception.approve`, never who asked, D-060). Approved, everything the work order still has at
 * production moves to the plant's scrap yard, no longer the work order's; the work order can then be closed.
 */
final class WoCloseExceptionController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Ask for a close exception (Idempotency-Key header required): 201 with the request, 200 when the same request was made
     * before under the key. Nothing moves until a second person approves it.
     */
    public function store(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // the scrap yard the material goes to; the first active one of the plant when omitted
            'scrapLocationId' => ['nullable', 'uuid'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $wo = $this->workOrder($db, $order);
        $this->authorizer->authorize($db, 'planning.wo_close_exception.request_correction', Scope::plant((string) $wo->plant_id, (string) $wo->company_id));
        if (! in_array($wo->status, WorkOrderController::CLOSABLE, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Work order not open', "{$wo->wo_no} is ".strtolower((string) $wo->status).'.');
        }
        // another request waiting (a retry under this key replays its own request instead)
        if ($db->selectOne("select 1 as ok from inventory_documents where doc_type = 'WO_CLOSE_EXCEPTION' and status = 'SUBMITTED' and request -> 'legs' -> 0 ->> 'woId' = ?::text
                               and idempotency_key <> ?", [$wo->id, $key]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Exception already asked', "A close exception of {$wo->wo_no} is already waiting for its decision.");
        }
        $scrap = isset($data['scrapLocationId'])
            ? $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = 'SCRAP_YARD' and status = 'ACTIVE'", [strtolower($data['scrapLocationId']), $wo->plant_id])
            : $db->selectOne("select id from locations where plant_id = ? and location_type = 'SCRAP_YARD' and status = 'ACTIVE' order by code limit 1", [$wo->plant_id]);
        $to = (string) ($scrap ?? throw ValidationException::withMessages(['scrapLocationId' => 'Choose an active scrap yard of this plant.']))->id;
        $left = $db->select(
            "select m.location_id, m.item_id, m.lot_id, trim_scale(sum(m.qty_signed))::text as qty
               from inventory_movements m join locations l on l.id = m.location_id and l.location_type = 'MAT_AT_PROD'
              where m.wo_id = ? and m.stock_status = 'UNRESTRICTED' group by m.location_id, m.item_id, m.lot_id having sum(m.qty_signed) > 0
              order by m.location_id, m.item_id, m.lot_id",
            [$wo->id],
        );
        if ($left === []) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Nothing left at production', "{$wo->wo_no} has no material left at production.");
        }
        $legs = [];
        foreach ($left as $m) {
            $legs[] = new PostingLeg('WO_CLOSE_SCRAP', (string) $m->location_id, (string) $m->item_id, $m->lot_id, 'UNRESTRICTED', '-'.$m->qty, woId: (string) $wo->id);
            $legs[] = new PostingLeg('WO_CLOSE_SCRAP', $to, (string) $m->item_id, $m->lot_id, 'UNRESTRICTED', (string) $m->qty);
        }
        $result = $this->postings->submit($db, new PostingCommand('WO_CLOSE_EXCEPTION', (string) $wo->plant_id, $key, $legs, note: trim($data['reason'])), 'WO_CLOSE');

        return response()->json(['data' => $result + ['status' => 'SUBMITTED']], $result['replayed'] ? 200 : 201);
    }

    /** Approve a close exception (Idempotency-Key header required): posted now, by the approver. 201 posted, 200 replayed. */
    public function approve(Request $request, string $exception): JsonResponse
    {
        $key = $this->key($request);
        $db = $this->db();

        return $this->answer($db, $this->postings->approve($db, $this->exception($db, $exception), $key));
    }

    /** Reject a close exception with the reason; nothing moves. 204, also when it was already rejected. */
    public function reject(Request $request, string $exception): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $this->postings->reject($db, $this->exception($db, $exception), trim($data['reason']));

        return response()->noContent();
    }

    private function workOrder(EoraPostgresConnection $db, string $id): stdClass
    {
        $id = strtolower($id);
        $wo = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne('select w.id, w.wo_no, w.status, w.plant_id, p.company_id from work_orders w join plants p on p.id = w.plant_id where w.id = ? for update of w', [$id])
            : null;

        return $wo ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
    }

    /** The close exception, when the actor approves them in its plant (404 for anything else). */
    private function exception(EoraPostgresConnection $db, string $id): string
    {
        $id = strtolower($id);
        $doc = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne("select d.plant_id, p.company_id from inventory_documents d join plants p on p.id = d.plant_id where d.id = ? and d.doc_type = 'WO_CLOSE_EXCEPTION'", [$id])
            : null;
        if ($doc === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->authorizer->authorize($db, 'planning.wo_close_exception.approve', Scope::plant((string) $doc->plant_id, (string) $doc->company_id));

        return $id;
    }
}
