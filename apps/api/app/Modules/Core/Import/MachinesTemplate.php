<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Machines in active work centers (doc 29 §1.6); the machine's plant is its work center's. */
final class MachinesTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'machines';
    }

    public function title(): string
    {
        return 'Machines';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Work center code', true, 'Code of an active work center of that plant.'),
            new ImportColumn('Code', true, '1–20 characters A-Z, 0-9, "_" or "-"; unique in the plant.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Stroke counter', false, 'YES when the machine reports stroke readings; empty = NO.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 ? 'Code: use 1–20 characters A-Z, 0-9, "_" or "-".' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
            RowRules::required($row['Work center code'], 'Work center code'),
            RowRules::flag($row['Stroke counter'], 'Stroke counter'),
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        if ($plant !== null && $row['Work center code'] !== '') {
            $wc = $db->selectOne('select status from work_centers where plant_id = ? and code = ?', [$plant->id, strtoupper($row['Work center code'])]);
            if ($wc === null) {
                $errors[] = 'Work center '.strtoupper($row['Work center code']).' does not exist in this plant.';
            } elseif ($wc->status !== 'ACTIVE') {
                $errors[] = 'Work center '.strtoupper($row['Work center code']).' is inactive.';
            }
        }
        if ($errors === [] && $plant !== null && $db->selectOne('select 1 as ok from machines where plant_id = ? and code = ?', [$plant->id, $code]) !== null) {
            $errors[] = "Machine {$code} already exists in plant ".strtoupper($row['Plant code']).'.';
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'machine', strtoupper($row['Plant code']).'/'.$code, $rowNo, "Machine {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $wc = $db->selectOne(
            'select w.id, w.plant_id from work_centers w join plants p on p.id = w.plant_id where p.code = ? and w.code = ?',
            [strtoupper($row['Plant code']), strtoupper($row['Work center code'])],
        );
        $id = (string) $db->selectOne(
            'insert into machines (tenant_id, plant_id, work_center_id, code, name, stroke_counter) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$wc->plant_id, $wc->id, strtoupper($row['Code']), $row['Name'], RowRules::isYes($row['Stroke counter'], false)],
        )->id;

        return $this->created($db, 'machines', 'machine', $id, ['code' => strtoupper($row['Code']), 'workCenterId' => (string) $wc->id], (string) $wc->plant_id);
    }
}
