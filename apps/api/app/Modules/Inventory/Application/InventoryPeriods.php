<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Outbox\OutboxPublisher;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Monthly inventory periods per plant (M3-D8, BR-PER-01/03, D-102): a past month is closed on a request decided by a
 * second person, and reopened the same way as an exceptional event. A CLOSING or CLOSED month takes no postings
 * (InventoryPostingService::requireOpenPeriod). The database guards every transition (migrations 002200, 002400); the
 * actor of each step is the signed actor. Authorization is the caller's.
 */
final class InventoryPeriods
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditRecorder $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** Asks to close a past month of the plant (`YYYY-MM`); refused while requests of that month wait for a decision. */
    public function requestClose(EoraPostgresConnection $db, string $plantId, string $month): stdClass
    {
        $today = $this->calendar->resolve($db, $plantId, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
        $period = $month.'-01';
        if ($period >= substr($today, 0, 7).'-01') {
            throw ValidationException::withMessages(['period' => 'Only a month that has ended is closed.']);
        }
        $db->insert(
            'insert into inventory_periods (tenant_id, plant_id, period) values ((select c.tenant from ctx_api.context() c), ?, ?::date) on conflict (tenant_id, plant_id, period) do nothing',
            [$plantId, $period],
        );
        $row = $this->locked($db, null, $plantId, $period);
        if ($row->status !== 'OPEN') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already closing', $row->status === 'CLOSED' ? "{$month} is already closed." : "A close of {$month} is already waiting for approval.");
        }
        $pending = (int) $db->selectOne(
            "select count(*) as n from inventory_documents where plant_id = ? and status = 'SUBMITTED' and effective_date >= ?::date and effective_date < (?::date + interval '1 month')",
            [$plantId, $period, $period],
        )->n;
        if ($pending > 0) {
            throw new DomainProblem(409, 'PERIOD_HAS_PENDING_REQUESTS', 'Requests still waiting',
                "{$pending} adjustment or reversal request(s) of {$month} still wait for a decision. Approve or reject them first.");
        }

        return $this->move($db, $row, "status = 'CLOSING', close_requested_by = (select c.actor from ctx_api.context() c), close_requested_at = now()",
            'inventory_period.close_requested', ['month' => $month]);
    }

    /** Closes a month whose close was requested by someone else. */
    public function close(EoraPostgresConnection $db, string $id): stdClass
    {
        $row = $this->locked($db, $id);
        $this->expect($row, 'CLOSING', 'No close of this month is waiting.');
        $this->secondPerson($db, (string) $row->close_requested_by, 'A month is closed by someone other than the person who asked to close it.');

        return $this->move($db, $row, "status = 'CLOSED', closed_by = (select c.actor from ctx_api.context() c), closed_at = now()", 'inventory_period.closed', []);
    }

    /** A close request withdrawn by its requester or rejected by an approver (with the reason): the month stays open. */
    public function returnClose(EoraPostgresConnection $db, string $id, ?string $reason): stdClass
    {
        $row = $this->locked($db, $id);
        $this->expect($row, 'CLOSING', 'No close of this month is waiting.');

        return $this->move($db, $row, "status = 'OPEN', close_requested_by = null, close_requested_at = null",
            'inventory_period.close_returned', ['reason' => $reason]);
    }

    /** Asks to reopen a closed month (BR-PER-03): an exceptional event with its reason. */
    public function requestReopen(EoraPostgresConnection $db, string $id, string $reason): stdClass
    {
        $row = $this->locked($db, $id);
        $this->expect($row, 'CLOSED', 'Only a closed month is reopened.');
        if ($row->reopen_requested_by !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already requested', 'A reopen of this month is already waiting for approval.');
        }

        return $this->move($db, $row, 'reopen_requested_by = (select c.actor from ctx_api.context() c), reopen_requested_at = now(), reopen_reason = ?',
            'inventory_period.reopen_requested', ['reason' => $reason, 'exceptional' => true], [$reason]);
    }

    /** Reopens a closed month on a request made by someone else; postings into it are allowed again. */
    public function reopen(EoraPostgresConnection $db, string $id): stdClass
    {
        $row = $this->locked($db, $id);
        $this->expect($row, 'CLOSED', 'Only a closed month is reopened.');
        if ($row->reopen_requested_by === null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not requested', 'No reopen of this month is waiting.');
        }
        $this->secondPerson($db, (string) $row->reopen_requested_by, 'A month is reopened by someone other than the person who asked to reopen it.');

        return $this->move($db, $row,
            "status = 'OPEN', reopened_by = (select c.actor from ctx_api.context() c), reopened_at = now(), closed_by = null, closed_at = null,
             close_requested_by = null, close_requested_at = null, reopen_requested_by = null, reopen_requested_at = null, reopen_reason = null",
            'inventory_period.reopened', ['reason' => $row->reopen_reason, 'exceptional' => true]);
    }

    /** A reopen request withdrawn by its requester or rejected by an approver (with the reason): the month stays closed. */
    public function returnReopen(EoraPostgresConnection $db, string $id, ?string $reason): stdClass
    {
        $row = $this->locked($db, $id);
        $this->expect($row, 'CLOSED', 'Only a closed month is reopened.');
        if ($row->reopen_requested_by === null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not requested', 'No reopen of this month is waiting.');
        }

        return $this->move($db, $row, 'reopen_requested_by = null, reopen_requested_at = null, reopen_reason = null',
            'inventory_period.reopen_returned', ['reason' => $reason, 'request' => $row->reopen_reason]);
    }

    /** The period row (by id, or plant + month), locked until the caller's transaction ends; 404 when unknown. */
    public function locked(EoraPostgresConnection $db, ?string $id, ?string $plantId = null, ?string $period = null): stdClass
    {
        $columns = "id, plant_id, to_char(period, 'YYYY-MM') as month, status, close_requested_by, reopen_requested_by, reopen_reason";

        return ($id !== null
            ? $db->selectOne("select {$columns} from inventory_periods where id = ? for update", [$id])
            : $db->selectOne("select {$columns} from inventory_periods where plant_id = ? and period = ?::date for update", [$plantId, $period]))
            ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
    }

    private function expect(stdClass $row, string $status, string $why): void
    {
        if ($row->status !== $status) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not possible now', $why);
        }
    }

    private function secondPerson(EoraPostgresConnection $db, string $requester, string $why): void
    {
        if ($requester === (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Second person required', $why);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  list<string>  $bindings
     */
    private function move(EoraPostgresConnection $db, stdClass $row, string $set, string $action, array $detail, array $bindings = []): stdClass
    {
        $before = (array) $db->selectOne('select * from inventory_periods where id = ?', [$row->id]);
        $db->update("update inventory_periods set {$set} where id = ?", [...$bindings, $row->id]);
        $after = $db->selectOne("select id, plant_id, to_char(period, 'YYYY-MM') as month, status from inventory_periods where id = ?", [$row->id]);
        $this->audit->record($db, $action, 'inventory_period', (string) $row->id, $before,
            (array) $db->selectOne('select * from inventory_periods where id = ?', [$row->id]), $detail + ['month' => $row->month], (string) $row->plant_id);
        $this->outbox->publish($db, 'inventory.period_changed', 'inventory_period', (string) $row->id, ['plantId' => $row->plant_id, 'month' => $row->month, 'status' => $after->status]);

        return $after;
    }
}
