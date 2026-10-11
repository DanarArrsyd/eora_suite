<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B1 step 1 (doc 25 §5.1, D-057): migrations run as eora_owner, requests and jobs as eora_app. The runtime role only
 * gets what the B0 queue infrastructure needs; tenant tables get their own grants in their creating migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE jobs, job_batches, failed_jobs TO eora_app');
        DB::statement('GRANT USAGE ON SEQUENCE jobs_id_seq, failed_jobs_id_seq TO eora_app');
    }

    public function down(): void
    {
        DB::statement('REVOKE ALL ON SEQUENCE jobs_id_seq, failed_jobs_id_seq FROM eora_app');
        DB::statement('REVOKE ALL ON TABLE jobs, job_batches, failed_jobs FROM eora_app');
    }
};
