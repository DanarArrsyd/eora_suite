<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use stdClass;

/**
 * Shared ground of the master-data templates (doc 29 §1.6, step 6b): version 1, valid rows commit, references by code,
 * plant scope of the uploading/committing user (the same `authorize` the screens apply), tenant calendar day, audit
 * of each created record with `source = import`.
 */
abstract class MasterTemplate implements ImportTemplate
{
    public function __construct(
        protected readonly AuditRecorder $audit,
        protected readonly Authorizer $authorizer,
    ) {}

    public function version(): int
    {
        return 1;
    }

    public function mode(): string
    {
        return self::VALID_ROWS;
    }

    /** The plant with this code, or null. */
    protected function plant(EoraPostgresConnection $db, string $code): ?stdClass
    {
        return $code === '' ? null : $db->selectOne('select id, company_id from plants where code = ?', [strtoupper($code)]);
    }

    /** Whether the acting user holds the template's permission in a scope covering the plant (as the screens check). */
    protected function covers(EoraPostgresConnection $db, stdClass $plant): bool
    {
        $target = Scope::plant((string) $plant->id, (string) $plant->company_id);
        foreach ($this->authorizer->scopes($db, $this->targetPermission()) as $scope) {
            if ($scope->covers($target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plant column check: known plant and in the user's scope. Sets `$plant`.
     *
     * @param  list<string>  $errors
     */
    protected function checkPlant(EoraPostgresConnection $db, string $code, array &$errors, ?stdClass &$plant): void
    {
        $plant = $this->plant($db, $code);
        if ($code === '') {
            $errors[] = 'Plant code is required.';
        } elseif ($plant === null) {
            $errors[] = 'Plant '.strtoupper($code).' does not exist.';
        } elseif (! $this->covers($db, $plant)) {
            $errors[] = 'Plant '.strtoupper($code).' is outside your roles.';
            $plant = null;
        }
    }

    /** Today (YYYY-MM-DD) in the tenant's time zone (D-044). */
    protected function today(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select (now() at time zone t.timezone)::date::text as d from tenants t where t.id = (select c.tenant from ctx_api.context() c)')->d;
    }

    /** A day as YYYY-MM-DD that is today or later; a message otherwise. */
    protected function dayFromToday(EoraPostgresConnection $db, string $value, string $header): ?string
    {
        if ($value === '') {
            return "{$header} is required.";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return "{$header}: enter a date as YYYY-MM-DD.";
        }
        $today = $this->today($db);

        return $value < $today ? "{$header}: today ({$today}) or later; past days keep what they had." : null;
    }

    /** A whole number in [min, max]; empty allowed (combine with required()). */
    protected static function wholeNumber(string $value, int $min, int $max, string $header): ?string
    {
        return $value === '' || (preg_match('/^\d{1,12}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max)
            ? null : "{$header}: enter a whole number from {$min} to {$max}.";
    }

    /**
     * Audits the created record (`<entity>.created`, source import) and returns its id.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function created(EoraPostgresConnection $db, string $table, string $entity, string $id, array $detail = [], ?string $plant = null): string
    {
        $this->audit->record($db, "{$entity}.created", $entity, $id, null, (array) $db->selectOne("select * from {$table} where id = ?", [$id]), $detail + ['source' => 'import'], $plant);

        return $id;
    }
}
