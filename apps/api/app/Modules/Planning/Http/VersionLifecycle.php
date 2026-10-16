<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Shared lifecycle steps of versioned engineering records approved by a second person (BOM versions, BOM–routing maps;
 * doc 26 §2.4a/§2.5): DRAFT → SUBMITTED → APPROVED, withdraw, reject with a reason, reopen. The database guard triggers
 * decide which transitions exist; the approval itself is a `lifecycle_api` function. Requires MasterWrites (`state`)
 * and an `AuditRecorder $audit` on the using class.
 */
trait VersionLifecycle
{
    /** Today (YYYY-MM-DD) in the tenant's time zone (D-044). */
    private function today(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select (now() at time zone t.timezone)::date::text as d from tenants t where t.id = (select c.tenant from ctx_api.context() c)')->d;
    }

    /** 409 unless the locked row is in `$status`. */
    private static function requireStatus(stdClass $row, string $status, string $noun): void
    {
        if ($row->status !== $status) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not possible in this state', sprintf('This needs a %s %s; this one is %s.', strtolower($status), $noun, strtolower((string) $row->status)));
        }
    }

    /** 403 SOD_VIOLATION when the signed actor prepared or edited the record (its contributor table, insert-self only). */
    private function refuseAuthor(EoraPostgresConnection $db, string $contributors, string $column, stdClass $row, string $message): void
    {
        $author = $db->selectOne(
            "select 1 as self from ctx_api.context() c
              where c.actor = ? or exists (select 1 from {$contributors} x where x.{$column} = ? and x.actor = c.actor)",
            [$row->prepared_by, $row->id],
        );
        if ($author !== null) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Not your own record', $message);
        }
    }

    /**
     * Moves the locked row to `$to` with the given extra columns and audits before/after.
     *
     * @param  array<string, mixed>  $extra  column => value (column names are code, never input)
     */
    private function moveTo(EoraPostgresConnection $db, string $table, string $entity, stdClass $row, string $to, array $extra, string $action, ?string $plant): void
    {
        $id = (string) $row->id;
        $before = $this->state($db, $table, $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", ['status', ...array_keys($extra)]));
        $db->update("update {$table} set {$set} where id = ?", [$to, ...array_values($extra), $id]);
        $this->audit->record($db, $action, $entity, $id, $before, $this->state($db, $table, $id), ['from' => $row->status, 'to' => $to], $plant);
    }

    /** SQLSTATEs of the lifecycle_api approval functions in the error catalogue's words. */
    private static function approvalFailure(QueryException $e, string $sodMessage): Throwable
    {
        return match ((string) $e->getCode()) {
            'EO010' => new DomainProblem(403, 'SCOPE_FORBIDDEN', 'Forbidden'),
            'EO011' => new DomainProblem(403, 'SOD_VIOLATION', 'Not your own record', $sodMessage),
            'EO012' => new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Cannot approve', self::reason($e)),
            'EO013' => ValidationException::withMessages(['version' => self::reason($e)]),
            'P0002' => new NotFoundHttpException,
            default => $e,
        };
    }

    private static function reason(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return preg_match('/ERROR:\s+(.+?)(?:\n|\(Connection|$)/', $message, $m) === 1 ? ucfirst(trim($m[1])).'.' : 'It cannot be approved.';
    }
}
