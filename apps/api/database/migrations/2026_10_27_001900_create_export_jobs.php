<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 7b (doc 08 §Secure export, doc 24 §3, D-092): queued exports of a list.
 *
 * - `export_jobs`: one export of one query resource with the list request it was made from (`query`: filter, q, sort).
 *   QUEUED → RUNNING → DONE, or FAILED (too many rows, no longer allowed, worker error); DONE → EXPIRED when its file
 *   is deleted 24 hours after it was written. The file holds what the requester may see, so a job is visible to its
 *   requester only (and to the system actor that expires files), never to the rest of the tenant. A link is issued only
 *   while the requester's current view scopes still cover `scopes`. A worker attempt claims the job with a lease and a
 *   generation and finishes it only with that generation (fencing).
 * - `inbox_notifications.deep_link`: the M1 check could never pass (regex repetition above 255); restated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE export_jobs (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              resource text NOT NULL CHECK (resource ~ '^[a-z]+\.[a-z_]{1,60}$'),
              query jsonb NOT NULL,
              format text NOT NULL DEFAULT 'XLSX' CHECK (format = 'XLSX'),
              status text NOT NULL DEFAULT 'QUEUED' CHECK (status IN ('QUEUED', 'RUNNING', 'DONE', 'FAILED', 'EXPIRED')),
              failure_code text CHECK (failure_code IN ('TOO_MANY_ROWS', 'NOT_ALLOWED', 'EXPORT_FAILED')),
              failure_detail text CHECK (length(failure_detail) <= 1000),
              requested_by_user uuid NOT NULL,
              row_count integer CHECK (row_count >= 0),
              -- the requester's view-permission scopes the file was written with: a link needs them still covered
              scopes jsonb,
              -- worker claim (fencing): an attempt finishes the job only with the generation it claimed
              claim_generation integer NOT NULL DEFAULT 0,
              lease_until timestamptz,
              file_key text,
              file_name text CHECK (length(file_name) BETWEEN 1 AND 200),
              file_size bigint CHECK (file_size > 0),
              started_at timestamptz,
              finished_at timestamptz,
              expires_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              CHECK ((status = 'FAILED') = (failure_code IS NOT NULL)),
              CHECK ((status = 'DONE') = (file_key IS NOT NULL AND expires_at IS NOT NULL))
            );
            CREATE INDEX export_jobs_requester_idx ON export_jobs (tenant_id, requested_by_user, created_at DESC);
            CREATE INDEX export_jobs_expiry_idx ON export_jobs (tenant_id, expires_at) WHERE status = 'DONE';

            CREATE OR REPLACE FUNCTION eora_guard_export_job() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF (NEW.tenant_id, NEW.resource, NEW.query, NEW.format, NEW.requested_by_user)
                 IS DISTINCT FROM (OLD.tenant_id, OLD.resource, OLD.query, OLD.format, OLD.requested_by_user) THEN
                RAISE EXCEPTION 'export job identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status IN ('FAILED', 'EXPIRED') AND NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a finished export job stays finished' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'DONE' AND NEW.status NOT IN ('DONE', 'EXPIRED') THEN
                RAISE EXCEPTION 'a written export only expires' USING ERRCODE = '23514';
              END IF;
              IF NEW.claim_generation < OLD.claim_generation THEN
                RAISE EXCEPTION 'claim generation never goes back' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_export_job() FROM PUBLIC;
            CREATE TRIGGER guard_identity BEFORE UPDATE ON export_jobs FOR EACH ROW EXECUTE FUNCTION eora_guard_export_job();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON export_jobs FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            ALTER TABLE export_jobs ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            -- the requester's own jobs; the system actor (file expiry) sees the tenant's jobs
            CREATE POLICY requester_only ON export_jobs TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)
                     AND (requested_by_user = (SELECT c.actor FROM ctx_api.context() c)
                          OR (SELECT c.actor FROM ctx_api.context() c) = '00000000-0000-0000-0000-000000000000'::uuid))
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)
                     AND (requested_by_user = (SELECT c.actor FROM ctx_api.context() c)
                          OR (SELECT c.actor FROM ctx_api.context() c) = '00000000-0000-0000-0000-000000000000'::uuid));
            GRANT SELECT, INSERT ON export_jobs TO eora_app;
            GRANT UPDATE (status, failure_code, failure_detail, row_count, scopes, claim_generation, lease_until, file_key, file_name, file_size,
                          started_at, finished_at, expires_at)
              ON export_jobs TO eora_app;

            -- the M1 deep-link check used a repetition count above Postgres' regex limit (255), so every notification with a
            -- link was refused; the export notification is the first to carry one: same rule, length checked apart
            ALTER TABLE inbox_notifications DROP CONSTRAINT inbox_notifications_deep_link_check;
            ALTER TABLE inbox_notifications ADD CONSTRAINT inbox_notifications_deep_link_check
              CHECK (deep_link ~ '^/[A-Za-z0-9/_.?=&-]*$' AND length(deep_link) <= 300);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inbox_notifications DROP CONSTRAINT inbox_notifications_deep_link_check;
            ALTER TABLE inbox_notifications ADD CONSTRAINT inbox_notifications_deep_link_check
              CHECK (deep_link ~ '^/[A-Za-z0-9/_.?=&-]{0,299}$') NOT VALID;
            DROP TABLE export_jobs;
            DROP FUNCTION eora_guard_export_job();
            SQL);
    }
};
