<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 exit (B2-AT-14): the lifecycle functions (approve/supersede/validate, `database/bootstrap/50_lifecycle.sql`) write
 * their audit rows with the request's correlation id like every other audit row; the definer role may now fill that
 * column too. The value comes from `app.correlation_id`, set with the signed context, and is a label only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('GRANT INSERT (correlation_id) ON audit_events TO eora_lifecycle_definer;');
    }

    public function down(): void
    {
        DB::unprepared('REVOKE INSERT (correlation_id) ON audit_events FROM eora_lifecycle_definer;');
    }
};
