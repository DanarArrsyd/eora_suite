<?php

declare(strict_types=1);

namespace App\Modules\Planning\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\GroupRefused;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\RowRules;
use LogicException;
use stdClass;

/**
 * Routings (doc 26 §5: one row per operation). Rows of the same plant + item + routing code form one new DRAFT routing
 * version: version 1 of a new routing, or the next version of an existing routing that has no open version (D-083).
 * Machines must sit in the operation's work center, dies in the routing's plant linked to the item — as on screen.
 */
final class RoutingsTemplate extends EngineeringTemplate
{
    private const array HEADER = ['Routing kind', 'Effective from', 'Standard yield %', 'Change reason'];

    public function code(): string
    {
        return 'routings';
    }

    public function title(): string
    {
        return 'Routings';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Item code', true, 'Code of the active item the routing makes.'),
            new ImportColumn('Routing code', true, '1–20 characters A-Z, 0-9, "_" or "-", e.g. R1. Rows with the same plant, item and code form one version.'),
            new ImportColumn('Routing kind', false, 'PRIMARY or ALTERNATE, for a new routing; empty = PRIMARY. Same on every row of the version.'),
            new ImportColumn('Effective from', true, 'Date YYYY-MM-DD, today or later. Same on every row of the version.'),
            new ImportColumn('Standard yield %', false, 'Above 0, at most 100, e.g. 98.5; needed before submitting. Same on every row of the version.'),
            new ImportColumn('Change reason', false, 'Required when the routing already exists. Same on every row of the version.'),
            new ImportColumn('Op seq', true, 'Whole number 1–9999, unique in the version, e.g. 10, 20.'),
            new ImportColumn('Step key', false, 'A-Z, 0-9, "_" or "-", starting with a letter, e.g. BLANK.'),
            new ImportColumn('Process type', true, 'Code of an active process type.'),
            new ImportColumn('Work center', true, 'Code of an active work center of the plant.'),
            new ImportColumn('Setup minutes', false, '0 or more, at most 2 decimals; empty = 0.'),
            new ImportColumn('Cycle seconds', true, '0 or more, at most 3 decimals.'),
            new ImportColumn('Cycle basis', true, 'PER_PIECE, PER_STROKE or PER_BATCH.'),
            new ImportColumn('Parts per cycle', false, 'Whole number 1–100000; empty = 1.'),
            new ImportColumn('Inspection required', false, 'YES or NO; empty = NO.'),
            new ImportColumn('Count point', false, 'YES or NO; empty = YES.'),
            new ImportColumn('Machine', false, 'Code of a machine of the work center (preferred machine).'),
            new ImportColumn('Die', false, 'Code of a die of the plant linked to the item; marks the operation as needing tooling.'),
            new ImportColumn('Cavities used', false, 'Whole number 1–1000, with a die.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Routing code']);
        $kind = strtoupper($row['Routing kind']);
        $yield = $row['Standard yield %'];
        $errors = RowRules::collect([
            RowRules::required($row['Item code'], 'Item code'),
            RowRules::required($code, 'Routing code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 ? 'Routing code: use 1–20 characters A-Z, 0-9, "_" or "-".' : null,
            RowRules::oneOf($kind, ['PRIMARY', 'ALTERNATE'], 'Routing kind'),
            $this->dayFromToday($db, $row['Effective from'], 'Effective from'),
            $yield !== '' && (preg_match('/^\d{1,3}(\.\d{1,3})?$/', $yield) !== 1 || (float) $yield <= 0 || (float) $yield > 100)
                ? 'Standard yield %: above 0, at most 100, with at most 3 decimals.' : null,
            RowRules::maxLength($row['Change reason'], 500, 'Change reason'),
            RowRules::required($row['Op seq'], 'Op seq'),
            self::wholeNumber($row['Op seq'], 1, 9999, 'Op seq'),
            $row['Step key'] !== '' && preg_match('/^[A-Z][A-Z0-9_-]{0,31}$/', strtoupper($row['Step key'])) !== 1 ? 'Step key: use A-Z, 0-9, "_" or "-", starting with a letter.' : null,
            RowRules::required($row['Process type'], 'Process type'),
            RowRules::required($row['Work center'], 'Work center'),
            $row['Setup minutes'] !== '' && preg_match('/^\d{1,8}(\.\d{1,2})?$/', $row['Setup minutes']) !== 1 ? 'Setup minutes: 0 or more with at most 2 decimals.' : null,
            RowRules::required($row['Cycle seconds'], 'Cycle seconds'),
            $row['Cycle seconds'] !== '' && preg_match('/^\d{1,8}(\.\d{1,3})?$/', $row['Cycle seconds']) !== 1 ? 'Cycle seconds: 0 or more with at most 3 decimals.' : null,
            RowRules::required($row['Cycle basis'], 'Cycle basis'),
            RowRules::oneOf($row['Cycle basis'], ['PER_PIECE', 'PER_STROKE', 'PER_BATCH'], 'Cycle basis'),
            self::wholeNumber($row['Parts per cycle'], 1, 100000, 'Parts per cycle'),
            RowRules::flag($row['Inspection required'], 'Inspection required'),
            RowRules::flag($row['Count point'], 'Count point'),
            self::wholeNumber($row['Cavities used'], 1, 1000, 'Cavities used'),
            $row['Cavities used'] !== '' && $row['Die'] === '' ? 'Cavities used needs a die.' : null,
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        $item = $row['Item code'] === '' ? null : $db->selectOne('select id, status from items where code = ?', [strtoupper($row['Item code'])]);
        if ($row['Item code'] !== '' && ($item === null || $item->status !== 'ACTIVE')) {
            $errors[] = 'Item '.strtoupper($row['Item code']).($item === null ? ' does not exist.' : ' is inactive.');
            $item = null;
        }
        if ($plant !== null && $item !== null && $code !== '') {
            $this->checkRouting($db, $plant, $item, $code, $row, $errors);
            $this->checkOperation($db, $plant, $item, $row, $errors);
        }
        $group = strtoupper($row['Plant code']).'/'.strtoupper($row['Item code']).'/'.$code;
        $this->sameAsGroup($seen, $group, $rowNo, $row, self::HEADER, "Routing {$code}", $errors);

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'op', $group.'/'.(int) $row['Op seq'], $rowNo, "Op seq {$row['Op seq']} of routing {$code}")]) : $errors;
    }

    public function finalize(EoraPostgresConnection $db, array $rows): array
    {
        return [];
    }

    public function commitAll(EoraPostgresConnection $db, array $rows): array
    {
        $ids = [];
        $groups = self::groups($rows, static fn (array $r) => strtoupper($r['Plant code']).'/'.strtoupper($r['Item code']).'/'.strtoupper($r['Routing code']));
        foreach ($groups as $group) {
            $first = $group[(int) array_key_first($group)] ?? [];
            $plant = $this->plant($db, $first['Plant code']) ?? throw new LogicException('The plant vanished after validation.');
            $item = (string) $db->selectOne('select id from items where code = ?', [strtoupper($first['Item code'])])->id;
            $code = strtoupper($first['Routing code']);
            $routing = $db->selectOne('select id from routings where plant_id = ? and item_id = ? and routing_code = ? for update', [$plant->id, $item, $code])?->id;
            // checked again under the lock: another job may have opened a version since this one was checked
            if ($routing !== null && $db->selectOne("select 1 as ok from routing_versions where routing_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$routing]) !== null) {
                throw new GroupRefused("Routing {$code} got an open version meanwhile. Finish or withdraw it first.");
            }
            if ($routing === null) {
                $routing = (string) $db->selectOne(
                    'insert into routings (tenant_id, plant_id, item_id, routing_code, routing_kind) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
                    [$plant->id, $item, $code, strtoupper($first['Routing kind']) ?: 'PRIMARY'],
                )->id;
                $this->created($db, 'routings', 'routing', $routing, ['routingCode' => $code], (string) $plant->id);
            }
            $no = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from routing_versions where routing_id = ?', [$routing])->n;
            $version = (string) $db->selectOne(
                'insert into routing_versions (tenant_id, plant_id, routing_id, version_no, effective_from, change_reason, standard_yield)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?) returning id',
                [$plant->id, $routing, $no, $first['Effective from'], RowRules::text($first['Change reason']), self::fraction($first['Standard yield %'])],
            )->id;
            $this->created($db, 'routing_versions', 'routing_version', $version, ['versionNo' => $no, 'operations' => count($group)], (string) $plant->id);
            foreach ($group as $rowNo => $row) {
                $ids[$rowNo] = $this->insertOperation($db, (string) $plant->id, $version, $row);
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     */
    private function checkRouting(EoraPostgresConnection $db, stdClass $plant, stdClass $item, string $code, array $row, array &$errors): void
    {
        $routing = $db->selectOne('select id from routings where plant_id = ? and item_id = ? and routing_code = ?', [$plant->id, $item->id, $code]);
        if ($routing === null) {
            if ((strtoupper($row['Routing kind']) ?: 'PRIMARY') === 'PRIMARY'
                && $db->selectOne("select 1 as ok from routings where plant_id = ? and item_id = ? and routing_kind = 'PRIMARY' and status = 'ACTIVE'", [$plant->id, $item->id]) !== null) {
                $errors[] = 'This item already has a primary routing in this plant; use its code or make this one ALTERNATE.';
            }

            return;
        }
        $open = $db->selectOne("select version_no from routing_versions where routing_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$routing->id]);
        if ($open !== null) {
            $errors[] = "Routing {$code} has an open version {$open->version_no}. Finish or withdraw it first.";
        }
        if (trim($row['Change reason']) === '') {
            $errors[] = "Change reason is required: routing {$code} already exists, so this is a new version.";
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     */
    private function checkOperation(EoraPostgresConnection $db, stdClass $plant, stdClass $item, array $row, array &$errors): void
    {
        if ($row['Process type'] !== '' && $db->selectOne("select 1 as ok from process_types where code = ? and status = 'ACTIVE'", [strtoupper($row['Process type'])]) === null) {
            $errors[] = 'Process type '.strtoupper($row['Process type']).' does not exist or is inactive.';
        }
        $wc = $row['Work center'] === '' ? null : $db->selectOne("select id from work_centers where plant_id = ? and code = ? and status = 'ACTIVE'", [$plant->id, strtoupper($row['Work center'])]);
        if ($row['Work center'] !== '' && $wc === null) {
            $errors[] = 'Work center '.strtoupper($row['Work center']).' is not an active work center of this plant.';
        }
        if ($row['Machine'] !== '' && $wc !== null
            && $db->selectOne("select 1 as ok from machines where work_center_id = ? and code = ? and status = 'ACTIVE'", [$wc->id, strtoupper($row['Machine'])]) === null) {
            $errors[] = 'Machine '.strtoupper($row['Machine']).' is not an active machine of work center '.strtoupper($row['Work center']).'.';
        }
        if ($row['Die'] !== '' && $db->selectOne(
            "select 1 as ok from dies d join die_items di on di.die_id = d.id and di.item_id = ? and di.status = 'ACTIVE' where d.plant_id = ? and d.code = ?",
            [$item->id, $plant->id, strtoupper($row['Die'])],
        ) === null) {
            $errors[] = 'Die '.strtoupper($row['Die']).' is not a die of this plant linked to item '.strtoupper($row['Item code']).'.';
        }
    }

    /** @param  array<string, string>  $row */
    private function insertOperation(EoraPostgresConnection $db, string $plant, string $version, array $row): string
    {
        $op = (string) $db->selectOne(
            'insert into routing_operations (tenant_id, plant_id, routing_version_id, op_seq, step_key, process_type_id, work_center_id,
                                             std_setup_minutes, std_cycle_seconds, cycle_basis, parts_per_cycle, inspection_required, is_count_point, requires_tooling)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, (select id from process_types where code = ?),
                     (select id from work_centers where plant_id = ? and code = ?), ?, ?, ?, ?, ?, ?, ?) returning id',
            [$plant, $version, (int) $row['Op seq'], RowRules::text(strtoupper($row['Step key'])), strtoupper($row['Process type']), $plant, strtoupper($row['Work center']),
                $row['Setup minutes'] === '' ? '0' : $row['Setup minutes'], $row['Cycle seconds'], strtoupper($row['Cycle basis']),
                $row['Parts per cycle'] === '' ? 1 : (int) $row['Parts per cycle'], RowRules::isYes($row['Inspection required'], false),
                RowRules::isYes($row['Count point'], true), $row['Die'] !== ''],
        )->id;
        if ($row['Machine'] !== '') {
            $db->insert(
                'insert into routing_operation_machines (tenant_id, operation_id, machine_id, is_preferred)
                 values ((select c.tenant from ctx_api.context() c), ?, (select m.id from machines m join work_centers w on w.id = m.work_center_id
                                                                          where w.plant_id = ? and w.code = ? and m.code = ?), true)',
                [$op, $plant, strtoupper($row['Work center']), strtoupper($row['Machine'])],
            );
        }
        if ($row['Die'] !== '') {
            $db->insert(
                'insert into routing_operation_tools (tenant_id, operation_id, die_id, quantity, cavities_used)
                 values ((select c.tenant from ctx_api.context() c), ?, (select id from dies where plant_id = ? and code = ?), 1, ?)',
                [$op, $plant, strtoupper($row['Die']), $row['Cavities used'] === '' ? null : (int) $row['Cavities used']],
            );
        }

        return $op;
    }
}
