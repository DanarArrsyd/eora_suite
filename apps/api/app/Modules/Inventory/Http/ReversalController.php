<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Reversal of a posted document (doc 04 row 13, D-060, D-101): asked with a reason by anyone who may request
 * corrections in the document's plant, approved (posted) or rejected by a second person. Every leg is negated with its
 * own code; nothing moves before approval, and approval is refused when the stock has moved on since.
 */
final class ReversalController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Request the reversal of a posted document (Idempotency-Key header required). 201 with the submitted request, 200
     * when the same request was submitted before under the key, 409 when the key was used for another request.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documentId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $id = $this->document($db, $data['documentId'], 'inventory.reversal.request_correction', null);
        $result = $this->postings->submitReversal($db, $id, $key, trim($data['reason']));

        return response()->json(['data' => $result + ['status' => 'SUBMITTED']], $result['replayed'] ? 200 : 201);
    }

    /**
     * Approve a submitted reversal (Idempotency-Key header required): it is posted now, by the approver, never by its
     * requester. 201 when posted, 200 when this key already posted it.
     */
    public function approve(Request $request, string $reversal): JsonResponse
    {
        $key = $this->key($request);
        $db = $this->db();

        return $this->answer($db, $this->postings->approve($db, $this->document($db, $reversal, 'inventory.reversal.approve', 'REVERSAL'), $key));
    }

    /** Reject a submitted reversal with the reason; nothing moves. 204, also when it was already rejected. */
    public function reject(Request $request, string $reversal): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $this->postings->reject($db, $this->document($db, $reversal, 'inventory.reversal.approve', 'REVERSAL'), trim($data['reason']));

        return response()->noContent();
    }

    /** The document, when the actor holds $permission in its plant (404 for anything else). */
    private function document(EoraPostgresConnection $db, string $id, string $permission, ?string $type): string
    {
        $id = strtolower($id);
        $doc = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne('select plant_id, doc_type from inventory_documents where id = ?', [$id])
            : null;
        if ($doc === null || ($type !== null && $doc->doc_type !== $type)) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->plant($db, (string) $doc->plant_id, $permission);

        return $id;
    }
}
