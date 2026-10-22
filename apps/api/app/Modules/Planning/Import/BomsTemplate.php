<?php

declare(strict_types=1);

namespace App\Modules\Planning\Import;

use App\Modules\Planning\Http\BomController;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\RowRules;

/**
 * BOMs (doc 26 §5: one row per line, O-026 gross quantity per piece in the line's unit). Rows of the same item form one
 * new DRAFT BOM version (D-078: any scope; D-083: next version only when no version is open). The component cycle is
 * checked at approval, as on screen.
 */
final class BomsTemplate extends EngineeringTemplate
{
    private const array HEADER = ['Effective from', 'Change reason'];

    public function code(): string
    {
        return 'boms';
    }

    public function title(): string
    {
        return 'BOMs';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Item code', true, 'Code of the active finished or Semi-Finished Material item. Rows with the same item form one version.'),
            new ImportColumn('Effective from', true, 'Date YYYY-MM-DD, today or later. Same on every row of the version.'),
            new ImportColumn('Change reason', false, 'Same on every row of the version.'),
            new ImportColumn('Line no', true, 'Whole number 1–9999, unique in the version, e.g. 10, 20.'),
            new ImportColumn('Component code', true, 'Code of an active item; not the BOM item itself.'),
            new ImportColumn('Quantity per piece', true, 'Gross quantity for one piece, above 0, at most 6 decimals.'),
            new ImportColumn('Unit', true, 'The component\'s base unit or a unit it has a conversion for.'),
            new ImportColumn('Scrap allowance %', false, '0 or more, below 100, at most 3 decimals; applied once.'),
            new ImportColumn('Purpose', false, 'At most 200 characters; needed when the component is on several lines.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $itemCode = strtoupper($row['Item code']);
        $componentCode = strtoupper($row['Component code']);
        $scrap = $row['Scrap allowance %'];
        $errors = RowRules::collect([
            RowRules::required($itemCode, 'Item code'),
            $this->dayFromToday($db, $row['Effective from'], 'Effective from'),
            RowRules::maxLength($row['Change reason'], 500, 'Change reason'),
            RowRules::required($row['Line no'], 'Line no'),
            self::wholeNumber($row['Line no'], 1, 9999, 'Line no'),
            RowRules::required($componentCode, 'Component code'),
            $itemCode !== '' && $itemCode === $componentCode ? 'An item is not a component of its own BOM.' : null,
            RowRules::required($row['Quantity per piece'], 'Quantity per piece'),
            RowRules::positiveDecimal($row['Quantity per piece'], 6, 'Quantity per piece'),
            RowRules::required($row['Unit'], 'Unit'),
            $scrap !== '' && (preg_match('/^\d{1,2}(\.\d{1,3})?$/', $scrap) !== 1) ? 'Scrap allowance %: 0 or more, below 100, with at most 3 decimals.' : null,
            RowRules::maxLength($row['Purpose'], 200, 'Purpose'),
        ]);
        $item = $itemCode === '' ? null : $db->selectOne('select id, item_type, status from items where code = ?', [$itemCode]);
        if ($itemCode !== '' && ($item === null || $item->status !== 'ACTIVE' || ! in_array($item->item_type, BomController::MADE_ITEM_TYPES, true))) {
            $errors[] = $item === null ? "Item {$itemCode} does not exist." : "Item {$itemCode} must be an active finished or Semi-Finished Material item.";
        } elseif ($item !== null) {
            $open = $db->selectOne("select version_no from bom_versions where item_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$item->id]);
            if ($open !== null) {
                $errors[] = "The BOM of {$itemCode} has an open version {$open->version_no}. Finish or withdraw it first.";
            }
        }
        $component = $componentCode === '' ? null : $db->selectOne('select id, base_uom_id, status from items where code = ?', [$componentCode]);
        if ($componentCode !== '' && ($component === null || $component->status !== 'ACTIVE')) {
            $errors[] = $component === null ? "Component {$componentCode} does not exist." : "Component {$componentCode} is inactive.";
        } elseif ($component !== null && $row['Unit'] !== '') {
            $unit = $db->selectOne('select id from uoms where code = ?', [strtoupper($row['Unit'])]);
            if ($unit === null || ($unit->id !== $component->base_uom_id
                && $db->selectOne('select 1 as ok from uom_conversions where item_id = ? and (from_uom_id = ? or to_uom_id = ?)', [$component->id, $unit->id, $unit->id]) === null)) {
                $errors[] = 'Unit '.strtoupper($row['Unit'])." is not the base unit of {$componentCode} or a unit it has a conversion for.";
            }
        }
        $this->sameAsGroup($seen, $itemCode, $rowNo, $row, self::HEADER, "BOM of {$itemCode}", $errors);

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'line', $itemCode.'/'.(int) $row['Line no'], $rowNo, "Line {$row['Line no']} of the BOM of {$itemCode}")]) : $errors;
    }

    /** A component on several lines of one BOM needs a purpose on each of them (D-B2-01, B2-AT-10). */
    public function finalize(EoraPostgresConnection $db, array $rows): array
    {
        $count = [];
        foreach ($rows as $row) {
            $key = strtoupper($row['Item code']).'/'.strtoupper($row['Component code']);
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
        $problems = [];
        foreach ($rows as $rowNo => $row) {
            if ($count[strtoupper($row['Item code']).'/'.strtoupper($row['Component code'])] > 1 && trim($row['Purpose']) === '') {
                $problems[$rowNo] = ['This component is on more than one line: say what this line is for, e.g. "bracket side".'];
            }
        }

        return $problems;
    }

    public function commitAll(EoraPostgresConnection $db, array $rows): array
    {
        $ids = [];
        foreach (self::groups($rows, static fn (array $r) => strtoupper($r['Item code'])) as $itemCode => $group) {
            $first = $group[(int) array_key_first($group)] ?? [];
            $item = (string) $db->selectOne('select id from items where code = ? for update', [$itemCode])->id;
            $no = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from bom_versions where item_id = ?', [$item])->n;
            $version = (string) $db->selectOne(
                'insert into bom_versions (tenant_id, item_id, version_no, effective_from, change_reason) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
                [$item, $no, $first['Effective from'], RowRules::text($first['Change reason'])],
            )->id;
            $this->created($db, 'bom_versions', 'bom_version', $version, ['versionNo' => $no, 'lines' => count($group)]);
            foreach ($group as $rowNo => $row) {
                $ids[$rowNo] = (string) $db->selectOne(
                    'insert into bom_lines (tenant_id, bom_version_id, line_no, component_item_id, gross_qty_per, uom_id, scrap_allowance, line_purpose)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, (select id from items where code = ?), ?, (select id from uoms where code = ?), ?, ?) returning id',
                    [$version, (int) $row['Line no'], strtoupper($row['Component code']), $row['Quantity per piece'], strtoupper($row['Unit']),
                        $row['Scrap allowance %'] === '' ? '0' : bcdiv($row['Scrap allowance %'], '100', 5), RowRules::text($row['Purpose'])],
                )->id;
            }
        }

        return $ids;
    }

    /** BOMs are per item and tenant-wide (D-078): no plant scope. */
    public function targetPermission(): string
    {
        return 'master.routing.create';
    }
}
