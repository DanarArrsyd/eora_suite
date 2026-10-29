<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 exit (B2-AT-12): the routing list finds routings by the process of an operation (`q`, e.g. "blank"); the process
 * types that match lead to their operations through this index instead of every operation of the tenant. The work
 * center filter already has `(tenant_id, work_center_id, id)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE INDEX routing_operations_process_idx ON routing_operations (tenant_id, process_type_id);');
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX routing_operations_process_idx;');
    }
};
