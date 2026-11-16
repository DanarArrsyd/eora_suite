<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-127: demand is entered by delivery. Sales orders (with customer POs, kanban and delivery schedules as their lines)
 * and forecasts are drafted, confirmed and activated by the Delivery template (DLV); the PPIC planner (PPC) reads them.
 *
 * Existing tenants follow through `eora:tenants:sync`, which also takes a permission away from a template role once
 * its template no longer has it (audited). For that the provisioner — never the application — may delete role
 * permission rows, inside the tenant's signed context.
 */
return new class extends Migration
{
    /** Permissions that move from PPC to DLV. */
    private const array MOVED = [
        'demand.sales_order.create', 'demand.sales_order.submit', 'demand.sales_order.post',
        'demand.forecast.create', 'demand.forecast.post',
        'demand.po.create', 'demand.po.submit', 'demand.po.post',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::MOVED as $code) {
                DB::insert("insert into role_template_permissions (template_code, permission_code) values ('DLV', ?) on conflict do nothing", [$code]);
                DB::delete("delete from role_template_permissions where template_code = 'PPC' and permission_code = ?", [$code]);
            }
            DB::unprepared('GRANT DELETE ON role_permissions TO eora_provisioner');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::unprepared('REVOKE DELETE ON role_permissions FROM eora_provisioner');
            foreach (self::MOVED as $code) {
                DB::insert("insert into role_template_permissions (template_code, permission_code) values ('PPC', ?) on conflict do nothing", [$code]);
                DB::delete("delete from role_template_permissions where template_code = 'DLV' and permission_code = ?", [$code]);
            }
        });
    }
};
