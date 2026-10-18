<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\ImportTemplate;
use App\Platform\Import\RowRules;

/** Material specifications (doc 29 §1.6): the first specification of an existing item; a set one is edited on screen. */
final class MaterialSpecsTemplate implements ImportTemplate
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function code(): string
    {
        return 'material_specs';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Material specifications';
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
            new ImportColumn('Item code', true, 'Code of an existing item.'),
            new ImportColumn('Grade', false, 'At most 60 characters, e.g. SPCC.'),
            new ImportColumn('Thickness mm', false, 'Above 0 and below 10,000,000, at most 3 decimals.'),
            new ImportColumn('Width mm', false, 'Above 0 and below 10,000,000, at most 3 decimals.'),
            new ImportColumn('Density g/cm3', false, 'Above 0 and below 10,000, at most 4 decimals, e.g. 7.85.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Item code']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Item code'),
            RowRules::maxLength($row['Grade'], 60, 'Grade'),
            // numeric(10,3) and numeric(8,4) in material_specs
            RowRules::positiveDecimal($row['Thickness mm'], 3, 'Thickness mm', 7),
            RowRules::positiveDecimal($row['Width mm'], 3, 'Width mm', 7),
            RowRules::positiveDecimal($row['Density g/cm3'], 4, 'Density g/cm3', 4),
            $row['Grade'].$row['Thickness mm'].$row['Width mm'].$row['Density g/cm3'] === '' ? 'Enter at least one property.' : null,
        ]);
        if ($code !== '') {
            $item = $db->selectOne('select id from items where code = ?', [$code]);
            if ($item === null) {
                $errors[] = "Item {$code} does not exist. Import or create it first.";
            } elseif ($db->selectOne('select 1 as ok from material_specs where item_id = ?', [$item->id]) !== null) {
                $errors[] = "Item {$code} already has a material specification; change it on the item's page.";
            }
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'spec', $code, $rowNo, "Item {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $item = (string) $db->selectOne('select id from items where code = ? for update', [strtoupper($row['Item code'])])->id;
        $id = (string) $db->selectOne(
            'insert into material_specs (tenant_id, item_id, grade, thickness_mm, width_mm, density_g_cm3) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$item, RowRules::text($row['Grade']), RowRules::text($row['Thickness mm']), RowRules::text($row['Width mm']), RowRules::text($row['Density g/cm3'])],
        )->id;
        $this->audit->record($db, 'material_spec.created', 'material_spec', $id, null, (array) $db->selectOne('select * from material_specs where id = ?', [$id]),
            ['itemId' => $item, 'source' => 'import']);

        return $id;
    }
}
