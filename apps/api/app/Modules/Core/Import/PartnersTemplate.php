<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Customers or suppliers (doc 29 §1.6): code + name, new records only. */
abstract class PartnersTemplate extends MasterTemplate
{
    abstract protected function table(): string;

    abstract protected function entity(): string;

    public function targetPermission(): string
    {
        return 'master.part.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Code', true, '1–20 characters A-Z, 0-9, ".", "_" or "-". Fixed once created.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $noun = ucfirst($this->entity());
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $code) !== 1 ? 'Code: use 1–20 characters A-Z, 0-9, ".", "_" or "-", starting with a letter or digit.' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
        ]);
        if ($errors === [] && $db->selectOne("select 1 as ok from {$this->table()} where code = ?", [$code]) !== null) {
            $errors[] = "{$noun} {$code} already exists.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, $this->entity(), $code, $rowNo, "{$noun} {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $code = strtoupper($row['Code']);
        $id = (string) $db->selectOne(
            "insert into {$this->table()} (tenant_id, code, name) values ((select c.tenant from ctx_api.context() c), ?, ?) returning id",
            [$code, $row['Name']],
        )->id;

        return $this->created($db, $this->table(), $this->entity(), $id, ['code' => $code]);
    }
}
