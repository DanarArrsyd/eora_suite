<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-128: master data has one door. Only the Supervisor template (SUP, which holds every permission, D-126) creates,
 * changes, deactivates and imports master data; field roles enter transactions and pick master data they can read.
 * Approvals stay with a second person: CSA approves routings, BOMs and conversion data (QCS also conversion data), never
 * the one who prepared them (D-060, SQL). The planner keeps lead times and safety stock (`planning.plan_parameter`).
 *
 * Existing tenants follow through `eora:tenants:sync`, which takes the permissions away from the template roles (D-127),
 * audited.
 */
return new class extends Migration
{
    /** template => permissions it gives up */
    private const array REMOVED = [
        'CSA' => [
            'master.part.create', 'master.org.create', 'master.routing.create', 'master.routing.submit',
            'master.conversion.create', 'master.conversion.submit',
            'exchange.import.create', 'exchange.import.submit', 'exchange.import.post', 'exchange.import.approve',
        ],
        'PPC' => ['master.part.create', 'master.routing.create', 'master.routing.submit', 'exchange.import.create', 'exchange.import.submit'],
        'MAT' => ['master.part.create', 'exchange.import.create', 'exchange.import.submit'],
        'INV' => ['master.part.create', 'exchange.import.create', 'exchange.import.submit'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::REMOVED as $template => $codes) {
                foreach ($codes as $code) {
                    DB::delete('delete from role_template_permissions where template_code = ? and permission_code = ?', [$template, $code]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::REMOVED as $template => $codes) {
                foreach ($codes as $code) {
                    DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?) on conflict do nothing', [$template, $code]);
                }
            }
        });
    }
};
