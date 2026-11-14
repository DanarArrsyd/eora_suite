<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M4 step 9 (doc 33 §1.9, D-118, doc 14 #18): lot trace, backward and forward, read from the ledger and its genealogy.
 *
 * Only a permission: CSA, INV and AUD see the trace of the plants they hold it in. Existing tenants receive it through
 * `eora:tenants:sync`.
 */
return new class extends Migration
{
    /** [code, action, sensitive, templates] */
    private const array PERMISSIONS = [
        ['traceability.lot_trace.view', 'view', false, ['CSA', 'INV', 'AUD']],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as [$code, $action, $sensitive, $templates]) {
            DB::insert("insert into permissions (code, module, action, sensitive) values (?, 'traceability', ?, ?) on conflict (code) do nothing", [$code, $action, $sensitive]);
            foreach ($templates as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as [$code]) {
            DB::delete('delete from role_template_permissions where permission_code = ?', [$code]);
            try {
                DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [$code]));
            } catch (QueryException $e) {
                // still held by a tenant role: the catalogue entry stays (as migration 002300)
                if ($e->getCode() !== '23503') {
                    throw $e;
                }
            }
        }
    }
};
