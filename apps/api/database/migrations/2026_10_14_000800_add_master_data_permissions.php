<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 1 (doc 29 §3 M2-D1, D-072): master-data permissions and their template mapping. Same conventions as the M1
 * catalogue (migration 000500): code "<module>.<resource>.<action>", every approve permission is sensitive (D-B1-25).
 * Organisation and part master have no approval lifecycle, so they carry view/create only (create = create and edit);
 * routing (routing, BOM, map) and conversion data are versioned with submit/approve (maker ≠ checker, D-060). Export
 * of master lists uses the existing `exchange.export.export` with the resource's view permission.
 *
 * Existing tenants receive the new template permissions through `eora:tenants:sync` (provisioner, per tenant).
 */
return new class extends Migration
{
    private const array ACTIONS = ['V' => 'view', 'C' => 'create', 'S' => 'submit', 'A' => 'approve'];

    private const array VIEWERS = ['PPC', 'MAT', 'PRL', 'QCI', 'QCS', 'FGW', 'DLV', 'INV', 'AUD'];

    /** @return array<string, array<string, string>> resource => template => letters */
    private function matrix(): array
    {
        $view = array_fill_keys(self::VIEWERS, 'V');

        return [
            'master.org' => ['CSA' => 'VC'] + $view,
            'master.part' => ['CSA' => 'VC', 'PPC' => 'VC', 'MAT' => 'VC', 'INV' => 'VC'] + $view,
            'master.routing' => ['CSA' => 'VCSA', 'PPC' => 'VCS'] + $view,
            'master.conversion' => ['CSA' => 'VCSA', 'QCS' => 'VA'] + $view,
        ];
    }

    public function up(): void
    {
        foreach ($this->matrix() as $resource => $byTemplate) {
            [$module, $name] = explode('.', $resource);
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

    /**
     * Removes the template mapping (new tenants no longer receive the permissions) and every catalogue entry no tenant
     * role uses. Tenant role copies sit behind forced RLS and are not the migration owner's to delete: a permission
     * still granted somewhere stays in the catalogue (FK), and a later `up` re-maps it without error.
     */
    public function down(): void
    {
        DB::delete("delete from role_template_permissions where permission_code like 'master.%'");
        foreach (DB::select("select code from permissions where code like 'master.%' order by code") as $p) {
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
