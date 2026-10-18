<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 6a (doc 29 §1.6, doc 26 §5, doc 08, ADR-0017, D-081): Excel import staging and ledgers.
 *
 * - `import_jobs`: one upload of one template version. Status RESERVED → VALIDATING → VALIDATED → SUBMITTED →
 *   COMMITTING → COMMITTED, or REJECTED (by a poster) / FAILED (header, file, worker lost). Atomic reservation: at most
 *   one active-or-committed job per (tenant, template, template version, file hash) — a retry of a failed or rejected
 *   file is a new job (`retry_of_job_id`, same `root_job_id`). Worker lease with fencing (`claim_generation`).
 * - `import_rows`: the staged rows of a job with their validation result (VALID / ERROR / SKIPPED / COMMITTED).
 * - `import_source_rows`: ledger of committed source rows per root job — `UNIQUE (tenant_id, root_job_id, row_no)`
 *   makes a second commit of the same row impossible, even by two jobs of one chain.
 * - `import_job_chunks`: committed chunks of one job, written in the same fenced transaction.
 * - CSA template also gets `exchange.import.create` / `submit` (D-081: a sole administrator runs the whole flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE import_jobs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              template text NOT NULL CHECK (template ~ '^[a-z][a-z_]{1,39}$'),
              template_version integer NOT NULL CHECK (template_version > 0),
              file_sha256 text NOT NULL CHECK (file_sha256 ~ '^[0-9a-f]{64}$'),
              file_name text NOT NULL CHECK (length(file_name) BETWEEN 1 AND 255),
              file_size integer NOT NULL CHECK (file_size > 0),
              storage_key text NOT NULL,
              error_file_key text,
              status text NOT NULL DEFAULT 'RESERVED'
                CHECK (status IN ('RESERVED', 'VALIDATING', 'VALIDATED', 'SUBMITTED', 'COMMITTING', 'COMMITTED', 'REJECTED', 'FAILED')),
              failure_code text CHECK (failure_code IN ('WORKER_LOST', 'HEADER_MISMATCH', 'UNREADABLE_FILE', 'NOT_ALLOWED')),
              failure_detail text CHECK (length(failure_detail) <= 1000),
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              retry_of_job_id uuid,
              root_job_id uuid NOT NULL,
              created_by_user uuid NOT NULL,
              submitted_by uuid,
              submitted_at timestamptz,
              committed_by uuid,
              commit_started_at timestamptz,
              committed_at timestamptz,
              total_rows integer NOT NULL DEFAULT 0,
              valid_rows integer NOT NULL DEFAULT 0,
              error_rows integer NOT NULL DEFAULT 0,
              skipped_rows integer NOT NULL DEFAULT 0,
              committed_rows integer NOT NULL DEFAULT 0,
              locked_by text,
              lease_until timestamptz,
              claim_generation integer NOT NULL DEFAULT 0,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              FOREIGN KEY (tenant_id, retry_of_job_id) REFERENCES import_jobs (tenant_id, id),
              FOREIGN KEY (tenant_id, root_job_id) REFERENCES import_jobs (tenant_id, id) DEFERRABLE INITIALLY DEFERRED,
              CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
              CHECK ((retry_of_job_id IS NULL) = (root_job_id = id))
            );
            -- atomic reservation (doc 26 §5): one active-or-committed job per file and template version
            CREATE UNIQUE INDEX import_jobs_active_file_uq ON import_jobs (tenant_id, template, template_version, file_sha256)
              WHERE status IN ('RESERVED', 'VALIDATING', 'VALIDATED', 'SUBMITTED', 'COMMITTING', 'COMMITTED');
            CREATE INDEX import_jobs_list_idx ON import_jobs (tenant_id, created_at DESC);
            CREATE INDEX import_jobs_root_idx ON import_jobs (tenant_id, root_job_id);

            CREATE TABLE import_rows (
              tenant_id uuid NOT NULL,
              job_id uuid NOT NULL,
              row_no integer NOT NULL CHECK (row_no > 0),
              data jsonb NOT NULL,
              status text NOT NULL CHECK (status IN ('VALID', 'ERROR', 'SKIPPED', 'COMMITTED')),
              errors jsonb NOT NULL DEFAULT '[]'::jsonb,
              entity_ref uuid,
              PRIMARY KEY (tenant_id, job_id, row_no),
              FOREIGN KEY (tenant_id, job_id) REFERENCES import_jobs (tenant_id, id)
            );
            CREATE INDEX import_rows_status_idx ON import_rows (tenant_id, job_id, status);

            CREATE TABLE import_source_rows (
              tenant_id uuid NOT NULL,
              root_job_id uuid NOT NULL,
              row_no integer NOT NULL CHECK (row_no > 0),
              status text NOT NULL DEFAULT 'COMMITTED' CHECK (status = 'COMMITTED'),
              committed_by_job_id uuid NOT NULL,
              claim_generation integer NOT NULL,
              entity_ref uuid,
              committed_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, root_job_id, row_no),
              FOREIGN KEY (tenant_id, root_job_id) REFERENCES import_jobs (tenant_id, id),
              FOREIGN KEY (tenant_id, committed_by_job_id) REFERENCES import_jobs (tenant_id, id)
            );

            CREATE TABLE import_job_chunks (
              tenant_id uuid NOT NULL,
              job_id uuid NOT NULL,
              chunk_no integer NOT NULL CHECK (chunk_no > 0),
              claim_generation integer NOT NULL,
              committed_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, job_id, chunk_no),
              FOREIGN KEY (tenant_id, job_id) REFERENCES import_jobs (tenant_id, id)
            );

            -- identity and history are fixed; ledgers are append-only
            CREATE OR REPLACE FUNCTION eora_guard_import_job() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF (NEW.tenant_id, NEW.template, NEW.template_version, NEW.file_sha256, NEW.storage_key, NEW.retry_of_job_id, NEW.root_job_id, NEW.created_by_user)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.template, OLD.template_version, OLD.file_sha256, OLD.storage_key, OLD.retry_of_job_id, OLD.root_job_id, OLD.created_by_user) THEN
                RAISE EXCEPTION 'import job identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('COMMITTED', 'REJECTED', 'FAILED') AND NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a finished import job stays finished' USING ERRCODE = '23514';
              END IF;
              IF NEW.claim_generation < OLD.claim_generation THEN
                RAISE EXCEPTION 'claim generation never goes back' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_import_job() FROM PUBLIC;
            CREATE TRIGGER guard_identity BEFORE UPDATE ON import_jobs FOR EACH ROW EXECUTE FUNCTION eora_guard_import_job();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON import_jobs FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['import_jobs', 'import_rows', 'import_source_rows', 'import_job_chunks'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            GRANT UPDATE (status, failure_code, failure_detail, reject_reason, error_file_key, submitted_by, submitted_at, committed_by,
                          commit_started_at, committed_at, total_rows, valid_rows, error_rows, skipped_rows, committed_rows, locked_by,
                          lease_until, claim_generation) ON import_jobs TO eora_app;
            GRANT UPDATE (status, errors, entity_ref) ON import_rows TO eora_app;
            GRANT DELETE ON import_rows TO eora_app;

            -- D-081: a sole administrator uploads and submits too
            INSERT INTO role_template_permissions (template_code, permission_code)
            VALUES ('CSA', 'exchange.import.create'), ('CSA', 'exchange.import.submit')
            ON CONFLICT DO NOTHING;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_template_permissions
             WHERE template_code = 'CSA' AND permission_code IN ('exchange.import.create', 'exchange.import.submit');
            DROP TABLE import_job_chunks;
            DROP TABLE import_source_rows;
            DROP TABLE import_rows;
            DROP TABLE import_jobs;
            DROP FUNCTION eora_guard_import_job();
            SQL);
    }
};
