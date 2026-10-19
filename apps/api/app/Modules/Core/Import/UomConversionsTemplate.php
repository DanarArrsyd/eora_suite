<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/**
 * Unit conversions per item (doc 29 §1.6, D-073): a version from a day that is not in the past; the open version of the
 * same unit pair is closed the day before, as on screen. "1 from-unit = factor to-units".
 */
final class UomConversionsTemplate extends MasterTemplate
{
    private const string PAIR = 'item_id = ? and least(from_uom_id, to_uom_id) = least(?::uuid, ?::uuid) and greatest(from_uom_id, to_uom_id) = greatest(?::uuid, ?::uuid)';

    public function code(): string
    {
        return 'uom_conversions';
    }

    public function title(): string
    {
        return 'Unit conversions';
    }

    public function targetPermission(): string
    {
        return 'master.part.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Item code', true, 'Code of an existing item.'),
            new ImportColumn('From unit', true, 'Unit code, e.g. SHEET.'),
            new ImportColumn('To unit', true, 'Another unit code, e.g. KG.'),
            new ImportColumn('Factor', true, '1 from-unit = factor to-units; above 0, at most 10 decimals.'),
            new ImportColumn('Effective from', true, 'Date YYYY-MM-DD, today or later.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $itemCode = strtoupper($row['Item code']);
        $fromCode = strtoupper($row['From unit']);
        $toCode = strtoupper($row['To unit']);
        $errors = RowRules::collect([
            RowRules::required($itemCode, 'Item code'),
            RowRules::required($fromCode, 'From unit'),
            RowRules::required($toCode, 'To unit'),
            $fromCode !== '' && $fromCode === $toCode ? 'From unit and To unit must differ.' : null,
            RowRules::required($row['Factor'], 'Factor'),
            RowRules::positiveDecimal($row['Factor'], 10, 'Factor', 10), // numeric(20,10)
            $this->dayFromToday($db, $row['Effective from'], 'Effective from'),
        ]);
        $ids = $this->ids($db, $itemCode, $fromCode, $toCode);
        foreach (['item' => "Item {$itemCode}", 'from' => "Unit {$fromCode}", 'to' => "Unit {$toCode}"] as $key => $what) {
            if ($ids[$key] === null && ($key === 'item' ? $itemCode : ($key === 'from' ? $fromCode : $toCode)) !== '') {
                $errors[] = "{$what} does not exist.";
            }
        }
        if ($errors === [] && $db->selectOne('select 1 as ok from uom_conversions where '.self::PAIR.' and effective_from >= ?',
            [$ids['item'], $ids['from'], $ids['to'], $ids['from'], $ids['to'], $row['Effective from']]) !== null) {
            $errors[] = 'A version of this conversion already starts on or after this day.';
        }
        $pair = [$fromCode, $toCode];
        sort($pair);

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'conversion', $itemCode.'/'.implode('/', $pair), $rowNo, "The {$fromCode}–{$toCode} conversion of {$itemCode}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $ids = $this->ids($db, strtoupper($row['Item code']), strtoupper($row['From unit']), strtoupper($row['To unit']));
        $db->selectOne('select id from items where id = ? for update', [$ids['item']]);
        $open = $db->selectOne('select id from uom_conversions where '.self::PAIR.' and effective_to is null', [$ids['item'], $ids['from'], $ids['to'], $ids['from'], $ids['to']]);
        if ($open !== null) {
            $before = (array) $db->selectOne('select * from uom_conversions where id = ?', [$open->id]);
            $db->update('update uom_conversions set effective_to = ?::date - 1 where id = ?', [$row['Effective from'], $open->id]);
            $this->audit->record($db, 'uom_conversion.closed', 'uom_conversion', (string) $open->id, $before,
                (array) $db->selectOne('select * from uom_conversions where id = ?', [$open->id]), ['itemId' => $ids['item'], 'source' => 'import']);
        }
        $id = (string) $db->selectOne(
            'insert into uom_conversions (tenant_id, item_id, from_uom_id, to_uom_id, factor, effective_from) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$ids['item'], $ids['from'], $ids['to'], $row['Factor'], $row['Effective from']],
        )->id;

        return $this->created($db, 'uom_conversions', 'uom_conversion', $id, ['itemId' => $ids['item']]);
    }

    /** @return array{item: ?string, from: ?string, to: ?string} */
    private function ids(EoraPostgresConnection $db, string $item, string $from, string $to): array
    {
        $one = static fn (string $sql, string $code): ?string => $code === '' ? null : ($db->selectOne($sql, [$code])?->id);

        return [
            'item' => $one('select id from items where code = ?', $item),
            'from' => $one('select id from uoms where code = ?', $from),
            'to' => $one('select id from uoms where code = ?', $to),
        ];
    }
}
