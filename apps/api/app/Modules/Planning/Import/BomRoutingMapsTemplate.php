<?php

declare(strict_types=1);

namespace App\Modules\Planning\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\RowRules;
use LogicException;
use stdClass;

/**
 * BOM–routing maps (doc 26 §5: one row per BOM line → the operation that consumes it). Rows of the same BOM version and
 * routing version form one new DRAFT map revision; both versions must be submitted or approved (D-079), every BOM line
 * mapped exactly once, the pair without an open revision (D-083). Plant scope of the routing.
 */
final class BomRoutingMapsTemplate extends EngineeringTemplate
{
    private const array LOCKED = ['SUBMITTED', 'APPROVED'];

    public function code(): string
    {
        return 'bom_routing_maps';
    }

    public function title(): string
    {
        return 'BOM–routing maps';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Plant of the routing, in your roles.'),
            new ImportColumn('Item code', true, 'Item of the BOM and the routing.'),
            new ImportColumn('BOM version', true, 'Version number of the item\'s BOM (submitted or approved).'),
            new ImportColumn('Routing code', true, 'Routing of the item in the plant.'),
            new ImportColumn('Routing version', true, 'Version number of that routing (submitted or approved).'),
            new ImportColumn('Line no', true, 'BOM line; every line of the BOM version once.'),
            new ImportColumn('Op seq', true, 'Operation of the routing version that consumes the line.'),
            new ImportColumn('Change reason', false, 'Same on every row of the map.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $errors = RowRules::collect([
            RowRules::required($row['Item code'], 'Item code'),
            RowRules::required($row['BOM version'], 'BOM version'),
            self::wholeNumber($row['BOM version'], 1, 100000, 'BOM version'),
            RowRules::required($row['Routing code'], 'Routing code'),
            RowRules::required($row['Routing version'], 'Routing version'),
            self::wholeNumber($row['Routing version'], 1, 100000, 'Routing version'),
            RowRules::required($row['Line no'], 'Line no'),
            self::wholeNumber($row['Line no'], 1, 9999, 'Line no'),
            RowRules::required($row['Op seq'], 'Op seq'),
            self::wholeNumber($row['Op seq'], 1, 9999, 'Op seq'),
            RowRules::maxLength($row['Change reason'], 500, 'Change reason'),
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        $pair = $errors === [] && $plant !== null ? $this->pair($db, $plant, $row, $errors) : null;
        if ($pair !== null) {
            if ($db->selectOne('select 1 as ok from bom_lines where bom_version_id = ? and line_no = ?', [$pair->bom_version_id, (int) $row['Line no']]) === null) {
                $errors[] = "BOM version {$row['BOM version']} has no line {$row['Line no']}.";
            }
            if ($db->selectOne('select 1 as ok from routing_operations where routing_version_id = ? and op_seq = ?', [$pair->routing_version_id, (int) $row['Op seq']]) === null) {
                $errors[] = "Routing version {$row['Routing version']} has no operation {$row['Op seq']}.";
            }
        }
        $group = self::key($row);
        $this->sameAsGroup($seen, $group, $rowNo, $row, ['Change reason'], 'Map', $errors);

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'map-line', $group.'/'.(int) $row['Line no'], $rowNo, "Line {$row['Line no']}")]) : $errors;
    }

    /** Every line of the BOM version needs an operation in the map (the approval would refuse a gap). */
    public function finalize(EoraPostgresConnection $db, array $rows): array
    {
        $problems = [];
        foreach (self::groups($rows, self::key(...)) as $group) {
            $firstNo = (int) array_key_first($group);
            $first = $group[$firstNo] ?? [];
            $plant = $this->plant($db, $first['Plant code']);
            $ignored = [];
            $pair = $plant === null ? null : $this->pair($db, $plant, $first, $ignored);
            if ($pair === null) {
                continue;
            }
            $mapped = array_map(static fn (array $r) => (int) $r['Line no'], $group);
            $missing = array_column(array_map(static fn ($l) => (array) $l, $db->select(
                'select line_no from bom_lines where bom_version_id = ? order by line_no', [$pair->bom_version_id],
            )), 'line_no');
            $missing = array_values(array_diff(array_map('intval', $missing), $mapped));
            if ($missing !== []) {
                $problems[$firstNo] = ['Every BOM line needs an operation; missing lines '.implode(', ', $missing).'.'];
            }
        }

        return $problems;
    }

    public function commitAll(EoraPostgresConnection $db, array $rows): array
    {
        $ids = [];
        foreach (self::groups($rows, self::key(...)) as $group) {
            $first = $group[(int) array_key_first($group)] ?? [];
            $plant = $this->plant($db, $first['Plant code']) ?? throw new LogicException('The plant vanished after validation.');
            $ignored = [];
            $pair = $this->pair($db, $plant, $first, $ignored) ?? throw new LogicException('The map pair changed after validation.');
            $db->selectOne('select id from bom_versions where id = ? for update', [$pair->bom_version_id]);
            $revision = (int) $db->selectOne('select coalesce(max(map_revision), 0) + 1 as n from bom_routing_maps where bom_version_id = ? and routing_version_id = ?',
                [$pair->bom_version_id, $pair->routing_version_id])->n;
            $map = (string) $db->selectOne(
                'insert into bom_routing_maps (tenant_id, item_id, plant_id, bom_version_id, routing_id, routing_version_id, map_revision, change_reason)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?) returning id',
                [$pair->item_id, $plant->id, $pair->bom_version_id, $pair->routing_id, $pair->routing_version_id, $revision, RowRules::text($first['Change reason'])],
            )->id;
            $this->created($db, 'bom_routing_maps', 'bom_routing_map', $map, ['mapRevision' => $revision, 'lines' => count($group)], (string) $plant->id);
            foreach ($group as $rowNo => $row) {
                $ids[$rowNo] = (string) $db->selectOne(
                    'insert into bom_routing_map_lines (tenant_id, map_id, bom_version_id, routing_version_id, bom_line_id, routing_operation_id)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, (select id from bom_lines where bom_version_id = ? and line_no = ?),
                             (select id from routing_operations where routing_version_id = ? and op_seq = ?)) returning id',
                    [$map, $pair->bom_version_id, $pair->routing_version_id, $pair->bom_version_id, (int) $row['Line no'], $pair->routing_version_id, (int) $row['Op seq']],
                )->id;
            }
        }

        return $ids;
    }

    /** @param  array<string, string>  $row */
    private static function key(array $row): string
    {
        return implode('/', [strtoupper($row['Plant code']), strtoupper($row['Item code']), (int) $row['BOM version'], strtoupper($row['Routing code']), (int) $row['Routing version']]);
    }

    /**
     * The BOM version and routing version the row names, both locked (D-079) and without an open map revision.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     */
    private function pair(EoraPostgresConnection $db, stdClass $plant, array $row, array &$errors): ?stdClass
    {
        $item = strtoupper($row['Item code']);
        $bom = $db->selectOne('select v.id, v.item_id, v.status from bom_versions v join items i on i.id = v.item_id where i.code = ? and v.version_no = ?', [$item, (int) $row['BOM version']]);
        $rv = $db->selectOne(
            'select v.id, v.status, v.routing_id from routing_versions v join routings r on r.id = v.routing_id join items i on i.id = r.item_id
              where r.plant_id = ? and i.code = ? and r.routing_code = ? and v.version_no = ?',
            [$plant->id, $item, strtoupper($row['Routing code']), (int) $row['Routing version']],
        );
        if ($bom === null) {
            $errors[] = "Item {$item} has no BOM version {$row['BOM version']}.";
        }
        if ($rv === null) {
            $errors[] = 'Routing '.strtoupper($row['Routing code'])." of {$item} in this plant has no version {$row['Routing version']}.";
        }
        if ($bom === null || $rv === null) {
            return null;
        }
        if (! in_array($bom->status, self::LOCKED, true) || ! in_array($rv->status, self::LOCKED, true)) {
            $errors[] = 'Submit the BOM version and the routing version first; a map is drawn on content that no longer changes.';

            return null;
        }
        if ($db->selectOne("select 1 as ok from bom_routing_maps where bom_version_id = ? and routing_version_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$bom->id, $rv->id]) !== null) {
            $errors[] = 'These versions already have an open map. Finish or withdraw it first.';

            return null;
        }

        return (object) ['item_id' => $bom->item_id, 'bom_version_id' => $bom->id, 'routing_id' => $rv->routing_id, 'routing_version_id' => $rv->id];
    }
}
