<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Database\QueryException;
use stdClass;

/**
 * Lifecycle steps shared by the two kinds of conversion data (doc 29 §1.5, D-080): net weight versions and conversion
 * profile versions. DRAFT → SUBMITTED → VALIDATED (a second person, `lifecycle_api`), withdraw, reject, reopen, and
 * RETIRED by a validator. Conversion data is tenant-wide: permissions are held in any scope. Requires VersionLifecycle,
 * MasterWrites and `$authorizer` / `$audit` on the using class.
 */
trait ConversionLifecycle
{
    private const string CREATE = 'master.conversion.create';

    private const string SUBMIT = 'master.conversion.submit';

    private const string APPROVE = 'master.conversion.approve';

    /**
     * The version row locked for a change in `$status`.
     *
     * @return array{0: EoraPostgresConnection, 1: stdClass}
     */
    private function lockedVersion(string $table, string $id, string $permission, string $status): array
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, $table, $id);
        $row = $db->selectOne("select * from {$table} where id = ? for update", [$id]);
        self::requireStatus($row, $status, 'version');

        return [$db, $row];
    }

    /** 409 when the record already has a DRAFT, SUBMITTED or REJECTED version (`$where` names the record). */
    private static function refuseOpenVersion(EoraPostgresConnection $db, string $table, string $where, string $id): void
    {
        if ($db->selectOne("select 1 as ok from {$table} where {$where} = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Open version exists', 'Finish or withdraw the open version first.');
        }
    }

    /** Validation by a second person through the database function, which also ends the version it replaces. */
    private function validateThrough(string $table, string $function, string $id): string
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::APPROVE);
        $id = $this->existing($db, $table, $id);
        try {
            $db->selectOne("select lifecycle_api.{$function}(?) as replaced", [$id]);
        } catch (QueryException $e) {
            throw self::approvalFailure($e, 'Conversion data is validated by someone who did not prepare or edit it.');
        }

        return $id;
    }

    /**
     * VALIDATED → RETIRED: no longer in force after today; a version that has not started yet never takes effect
     * (`effective_to = effective_from − 1`). A version that already ended stays as it is.
     */
    private function retireVersion(string $table, string $entity, string $id): string
    {
        [$db, $row] = $this->lockedVersion($table, $id, self::APPROVE, 'VALIDATED');
        $today = $this->today($db);
        if ($row->effective_to !== null && $row->effective_to < $today) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already ended', 'This version is no longer in force; nothing to retire.');
        }
        $end = $row->effective_from > $today
            ? (string) $db->selectOne('select (?::date - 1)::text as d', [$row->effective_from])->d
            : $today;
        $actor = (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
        $this->moveTo($db, $table, $entity, $row, 'RETIRED', ['effective_to' => $end, 'retired_at' => now()->toIso8601String(), 'retired_by' => $actor], "{$entity}.retired", null);

        return (string) $row->id;
    }
}
