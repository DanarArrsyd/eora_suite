<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Http\Concurrency;
use App\Platform\Query\QueryDefinition;
use App\Platform\Query\QueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared write path of the master-data controllers: visible-row lookup (404 otherwise), plant scope, insert with a
 * duplicate-code message, If-Match update with before/after audit, and the list-shaped response with ETag. Table and
 * column names passed in are code, never input.
 *
 * @property-read AuditRecorder $audit
 * @property-read QueryRunner $rows
 */
trait MasterWrites
{
    protected function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Master data requires the EORA PostgreSQL connection.');
    }

    /** Lower-cased id of a visible row, or 404 (also for malformed ids and other tenants' ids). */
    protected function existing(EoraPostgresConnection $db, string $table, string $id): string
    {
        $id = strtolower($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1
            || $db->selectOne("select 1 as ok from {$table} where id = ?", [$id]) === null) {
            throw new NotFoundHttpException;
        }

        return $id;
    }

    protected function plantScope(EoraPostgresConnection $db, string $plant): Scope
    {
        return Scope::plant($plant, (string) $db->selectOne('select company_id from plants where id = ?', [$plant])->company_id);
    }

    /** @param  list<mixed>  $bindings */
    protected function insert(EoraPostgresConnection $db, string $sql, array $bindings, string $duplicate): string
    {
        try {
            return (string) $db->selectOne($sql, $bindings)->id;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => $duplicate]);
            }
            throw $e;
        }
    }

    /**
     * Applies $changes to the row at $version (412 if another version committed first) and audits before/after.
     *
     * @param  array<string, mixed>  $changes  column => value (column names are code, never input)
     */
    protected function update(EoraPostgresConnection $db, string $table, string $entity, string $id, int $version, array $changes, string $action, ?string $plant = null): void
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['name' => 'Nothing to change.']);
        }
        $before = $this->state($db, $table, $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($changes)));
        if ($db->update("update {$table} set {$set} where id = ? and version = ?", [...array_values($changes), $id, $version]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, $action, $entity, $id, $before, $this->state($db, $table, $id), ['changed' => array_keys($changes)], $plant);
    }

    /** @return array<string, mixed> the stored row, the same projection before and after a change */
    protected function state(EoraPostgresConnection $db, string $table, string $id): array
    {
        return (array) $db->selectOne("select * from {$table} where id = ?", [$id]);
    }

    protected function respond(EoraPostgresConnection $db, QueryDefinition $definition, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, $definition, $id) ?? throw new LogicException('Written row not visible.');

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
