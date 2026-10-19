<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Which parts a die makes (doc 29 §1.6): new links in the die's plant scope. */
final class DieItemsTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'die_items';
    }

    public function title(): string
    {
        return 'Die parts';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Die code', true, 'Code of an existing die in a plant of your roles.'),
            new ImportColumn('Item code', true, 'Code of an existing item.'),
            new ImportColumn('Parts per stroke', false, 'Whole number 1–1000; empty = 1.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $dieCode = strtoupper($row['Die code']);
        $itemCode = strtoupper($row['Item code']);
        $errors = RowRules::collect([
            RowRules::required($dieCode, 'Die code'),
            RowRules::required($itemCode, 'Item code'),
            self::wholeNumber($row['Parts per stroke'], 1, 1000, 'Parts per stroke'),
        ]);
        $die = $dieCode === '' ? null : $db->selectOne('select d.id, p.id as plant_id, p.company_id from dies d join plants p on p.id = d.plant_id where d.code = ?', [$dieCode]);
        if ($dieCode !== '' && $die === null) {
            $errors[] = "Die {$dieCode} does not exist.";
        } elseif ($die !== null && ! $this->covers($db, (object) ['id' => $die->plant_id, 'company_id' => $die->company_id])) {
            $errors[] = "Die {$dieCode} is in a plant outside your roles.";
        }
        $item = $itemCode === '' ? null : $db->selectOne('select id from items where code = ?', [$itemCode]);
        if ($itemCode !== '' && $item === null) {
            $errors[] = "Item {$itemCode} does not exist.";
        }
        if ($errors === [] && $db->selectOne('select 1 as ok from die_items where die_id = ? and item_id = ?', [$die->id, $item->id]) !== null) {
            $errors[] = "Die {$dieCode} is already linked to item {$itemCode}.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'die_item', "{$dieCode}/{$itemCode}", $rowNo, "Die {$dieCode} with item {$itemCode}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $die = $db->selectOne('select id, plant_id from dies where code = ?', [strtoupper($row['Die code'])]);
        $item = (string) $db->selectOne('select id from items where code = ?', [strtoupper($row['Item code'])])->id;
        $id = (string) $db->selectOne(
            'insert into die_items (tenant_id, die_id, item_id, parts_per_stroke) values ((select c.tenant from ctx_api.context() c), ?, ?, ?) returning id',
            [$die->id, $item, $row['Parts per stroke'] === '' ? 1 : (int) $row['Parts per stroke']],
        )->id;

        return $this->created($db, 'die_items', 'die_item', $id, ['dieId' => (string) $die->id, 'itemId' => $item], (string) $die->plant_id);
    }
}
