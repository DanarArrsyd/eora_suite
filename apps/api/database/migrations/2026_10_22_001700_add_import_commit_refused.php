<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 6c (D-B2-07, ADR-0017 §7): an all-or-nothing job whose rows no longer pass at commit (or that the database
 * refuses) fails as a whole with COMMIT_REFUSED — nothing is written; the same file uploaded again is a retry job.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE import_jobs DROP CONSTRAINT import_jobs_failure_code_check;
            ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_failure_code_check
              CHECK (failure_code IN ('WORKER_LOST', 'HEADER_MISMATCH', 'UNREADABLE_FILE', 'NOT_ALLOWED', 'COMMIT_REFUSED'));
            SQL);
    }

    /** Jobs already failed with COMMIT_REFUSED stay as history: the old rule applies to new rows only (NOT VALID). */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE import_jobs DROP CONSTRAINT import_jobs_failure_code_check;
            ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_failure_code_check
              CHECK (failure_code IN ('WORKER_LOST', 'HEADER_MISMATCH', 'UNREADABLE_FILE', 'NOT_ALLOWED')) NOT VALID;
            SQL);
    }
};
