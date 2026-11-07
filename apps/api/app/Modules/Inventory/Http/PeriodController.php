<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPeriods;
use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Period close and reopen (M3-D8, BR-PER-01/03, doc 18, D-102): inventory control asks to close a past month and a
 * second person with `inventory.period_close.approve` closes it; a CSA asks to reopen a closed month and another holder
 * of `inventory.period_reopen.approve` reopens it. A request is withdrawn by its requester or rejected (with the reason)
 * by an approver. Every answer is the period: id, plant, month, status.
 */
final class PeriodController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPeriods $periods,
    ) {}

    /** Ask to close a month that has ended (`YYYY-MM`). */
    public function requestClose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'inventory.period_close.post');

        return self::period($this->periods->requestClose($db, $plant, $data['period']), 201);
    }

    /** Close a month whose close someone else asked for. */
    public function close(string $period): JsonResponse
    {
        $db = $this->db();

        return self::period($this->periods->close($db, $this->scoped($db, $period, 'inventory.period_close.approve')));
    }

    /** Withdraw a close request (its requester) or reject it with the reason (an approver); the month stays open. */
    public function returnClose(Request $request, string $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = self::optionalText($data['reason'] ?? null);
        $db = $this->db();
        $id = $this->returner($db, $period, 'inventory.period_close.post', 'inventory.period_close.approve', 'close_requested_by', $reason);

        return self::period($this->periods->returnClose($db, $id, $reason));
    }

    /** Ask to reopen a closed month, with the reason (BR-PER-03). */
    public function requestReopen(Request $request, string $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();

        return self::period($this->periods->requestReopen($db, $this->scoped($db, $period, 'inventory.period_reopen.request_correction'), trim($data['reason'])));
    }

    /** Reopen a closed month on a request someone else made. */
    public function reopen(string $period): JsonResponse
    {
        $db = $this->db();

        return self::period($this->periods->reopen($db, $this->scoped($db, $period, 'inventory.period_reopen.approve')));
    }

    /** Withdraw a reopen request (its requester) or reject it with the reason (an approver); the month stays closed. */
    public function returnReopen(Request $request, string $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = self::optionalText($data['reason'] ?? null);
        $db = $this->db();
        $id = $this->returner($db, $period, 'inventory.period_reopen.request_correction', 'inventory.period_reopen.approve', 'reopen_requested_by', $reason);

        return self::period($this->periods->returnReopen($db, $id, $reason));
    }

    /** The period, when the actor holds $permission in its plant (404 for anything else). */
    private function scoped(EoraPostgresConnection $db, string $id, string $permission): string
    {
        $id = strtolower($id);
        $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne('select plant_id from inventory_periods where id = ?', [$id])
            : null;
        if ($row === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->plant($db, (string) $row->plant_id, $permission);

        return $id;
    }

    /**
     * Its requester withdraws a request (with the request permission); anyone else needs the approve permission and a
     * reason to reject it.
     */
    private function returner(EoraPostgresConnection $db, string $id, string $requestPermission, string $approvePermission, string $requesterColumn, ?string $reason): string
    {
        $id = strtolower($id);
        $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            // locked until the transition commits: a concurrent reject + new request cannot change whose request this is
            ? $db->selectOne("select {$requesterColumn} as requester from inventory_periods where id = ? for update", [$id])
            : null;
        if ($row === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $mine = $row->requester !== null && (string) $row->requester === (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
        if (! $mine && ($reason === null || mb_strlen($reason) < 3)) {
            throw ValidationException::withMessages(['reason' => 'Say why the request is rejected.']);
        }

        return $this->scoped($db, $id, $mine ? $requestPermission : $approvePermission);
    }

    private static function period(stdClass $row, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ['id' => $row->id, 'plantId' => $row->plant_id, 'period' => $row->month, 'status' => $row->status]], $status);
    }
}
