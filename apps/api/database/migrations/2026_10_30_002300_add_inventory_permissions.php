<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M3 step 1 (doc 32 §3 M3-D1, D-096): the inventory permissions doc 18 lacked — receipts, incoming release, transfers
 * and the stock view — with their template mapping. Same conventions as migrations 000500/000800: code
 * "<module>.<resource>.<action>", every approve permission is sensitive (D-B1-25); MAD and APR start empty (D-068).
 * Adjustment, reversal, period close/reopen and lot split already exist (migration 000500).
 *
 * Existing tenants receive the new template permissions through `eora:tenants:sync` (provisioner, per tenant).
 */
return new class extends Migration
{
    private const array ACTIONS = ['V' => 'view', 'C' => 'create', 'S' => 'submit', 'P' => 'post', 'A' => 'approve'];

    private const array ALL = ['CSA', 'PPC', 'MAT', 'PRL', 'OPR', 'QCI', 'QCS', 'FGW', 'DLV', 'INV', 'AUD'];

    /** @return array<string, array<string, string>> resource => template => letters */
    private function matrix(): array
    {
        return [
            'materials.receipt' => ['MAT' => 'VCSP', 'CSA' => 'V', 'PPC' => 'V', 'INV' => 'V', 'AUD' => 'V'],
            'materials.incoming_release' => ['QCI' => 'VP', 'QCS' => 'VP', 'MAT' => 'V', 'INV' => 'V', 'AUD' => 'V'],
            'materials.transfer' => ['MAT' => 'VCSP', 'FGW' => 'VCSP', 'INV' => 'VCSP', 'AUD' => 'V'],
            'inventory.stock' => array_fill_keys(self::ALL, 'V'),
        ];
    }

    public function up(): void
    {
        foreach ($this->matrix() as $resource => $byTemplate) {
            [$module] = explode('.', $resource);
            $codes = [];
            foreach ($byTemplate as $template => $letters) {
                foreach (str_split($letters) as $letter) {
                    $codes[$resource.'.'.self::ACTIONS[$letter]][] = $template;
                }
            }
            ksort($codes);
            foreach ($codes as $code => $templates) {
                $action = substr($code, strrpos($code, '.') + 1);
                // idempotent: a rollback keeps permissions still referenced by tenant roles (see down())
                DB::insert('insert into permissions (code, module, action, sensitive) values (?, ?, ?, ?) on conflict (code) do nothing', [$code, $module, $action, $action === 'approve']);
                foreach ($templates as $template) {
                    DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
                }
            }
        }
    }

    /** As migration 000800: the mapping goes, catalogue entries stay while a tenant role still uses them. */
    public function down(): void
    {
        $prefixes = array_map(static fn (string $r) => $r.'.%', array_keys($this->matrix()));
        $where = implode(' or ', array_fill(0, count($prefixes), 'permission_code like ?'));
        DB::delete("delete from role_template_permissions where {$where}", $prefixes);
        foreach (DB::select('select code from permissions where '.str_replace('permission_code', 'code', $where).' order by code', $prefixes) as $p) {
            try {
                DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [$p->code]));
            } catch (QueryException $e) {
                if ($e->getCode() !== '23503') {
                    throw $e;
                }
            }
        }
    }
};
