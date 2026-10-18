<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Modules\Core\Queries\ItemsQuery;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\ImportTemplate;
use App\Platform\Import\RowRules;

/**
 * Items (doc 29 §1.6, doc 26 §5; the illustrative 1,278-part load): new items only, each with an active base unit; an
 * existing code is reported, never changed (edits stay on screen, If-Match).
 */
final class ItemsTemplate implements ImportTemplate
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function code(): string
    {
        return 'items';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Items';
    }

    public function mode(): string
    {
        return self::VALID_ROWS;
    }

    public function targetPermission(): string
    {
        return 'master.part.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Code', true, '1–40 characters A-Z, 0-9, ".", "_", "/" or "-". Fixed once created.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Item type', true, 'RAW, SEMI_FINISHED, FINISHED, SCRAP or CONSUMABLE.'),
            new ImportColumn('Base unit', true, 'Code of an active unit, e.g. PCS, KG, SHEET.'),
            new ImportColumn('Lot controlled', false, 'YES or NO; empty = YES.'),
            new ImportColumn('Inspection required', false, 'YES or NO; empty = NO.'),
            new ImportColumn('Incoming release required', false, 'YES or NO; empty = NO.'),
            new ImportColumn('Drawing no', false, 'At most 60 characters.'),
            new ImportColumn('Drawing rev', false, 'At most 20 characters; needs a drawing no.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $unit = strtoupper($row['Base unit']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/', $code) !== 1 ? 'Code: use 1–40 characters A-Z, 0-9, ".", "_", "/" or "-".' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
            RowRules::required($row['Item type'], 'Item type'),
            RowRules::oneOf($row['Item type'], ItemsQuery::TYPES, 'Item type'),
            RowRules::required($unit, 'Base unit'),
            RowRules::flag($row['Lot controlled'], 'Lot controlled'),
            RowRules::flag($row['Inspection required'], 'Inspection required'),
            RowRules::flag($row['Incoming release required'], 'Incoming release required'),
            RowRules::maxLength($row['Drawing no'], 60, 'Drawing no'),
            RowRules::maxLength($row['Drawing rev'], 20, 'Drawing rev'),
            $row['Drawing rev'] !== '' && $row['Drawing no'] === '' ? 'Drawing rev needs a drawing no.' : null,
        ]);
        if ($unit !== '') {
            $uom = $db->selectOne('select status from uoms where code = ?', [$unit]);
            if ($uom === null) {
                $errors[] = "Unit {$unit} does not exist. Import or create it first.";
            } elseif ($uom->status !== 'ACTIVE') {
                $errors[] = "Unit {$unit} is inactive.";
            }
        }
        if ($errors === [] && $db->selectOne('select 1 as ok from items where code = ?', [$code]) !== null) {
            $errors[] = "Item {$code} already exists.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'item', $code, $rowNo, "Item {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $code = strtoupper($row['Code']);
        $id = (string) $db->selectOne(
            'insert into items (tenant_id, code, name, item_type, base_uom_id, lot_controlled, inspection_required, incoming_release_required, drawing_no, drawing_rev)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, (select id from uoms where code = ?), ?, ?, ?, ?, ?) returning id',
            [$code, $row['Name'], strtoupper($row['Item type']), strtoupper($row['Base unit']),
                RowRules::isYes($row['Lot controlled'], true), RowRules::isYes($row['Inspection required'], false),
                RowRules::isYes($row['Incoming release required'], false), RowRules::text($row['Drawing no']), RowRules::text($row['Drawing rev'])],
        )->id;
        $this->audit->record($db, 'item.created', 'item', $id, null, (array) $db->selectOne('select * from items where id = ?', [$id]),
            ['code' => $code, 'itemType' => strtoupper($row['Item type']), 'source' => 'import']);

        return $id;
    }
}
