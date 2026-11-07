<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M3 step 8 (doc 32 §1.8, BR-PER-03, D-102): who decides a period reopen, and how a reopen request is turned down.
 *
 * - `inventory.period_reopen.approve` (sensitive) for the CSA template: a reopen is requested by one CSA and approved by
 *   another (the requester never decides). The APR template stays empty (D-068); a tenant may add it there.
 * - The period guard also lets a pending reopen request be rejected or withdrawn (cleared on the CLOSED period); every
 *   other rule of the period lifecycle (migration 002200) is unchanged.
 *
 * Existing tenants receive the template permission through `eora:tenants:sync`.
 */
return new class extends Migration
{
    private const string CODE = 'inventory.period_reopen.approve';

    public function up(): void
    {
        DB::insert("insert into permissions (code, module, action, sensitive) values (?, 'inventory', 'approve', true) on conflict (code) do nothing", [self::CODE]);
        DB::insert("insert into role_template_permissions (template_code, permission_code) values ('CSA', ?) on conflict do nothing", [self::CODE]);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eora_guard_inventory_period() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'OPEN' OR NEW.closed_by IS NOT NULL OR NEW.reopened_by IS NOT NULL OR NEW.reopen_requested_by IS NOT NULL THEN
                  RAISE EXCEPTION 'a period starts open' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.plant_id, NEW.period) IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.period) THEN
                RAISE EXCEPTION 'a period is fixed' USING ERRCODE = '23514';
              END IF;
              -- every update is one of the transitions below; each pins the history it does not write. A reopen request is
              -- written only by the CLOSED → CLOSED request and cleared only by the CLOSED → OPEN decision.
              IF NOT (OLD.status = 'CLOSED' AND NEW.status IN ('CLOSED', 'OPEN'))
                 AND (NEW.reopen_requested_by, NEW.reopen_requested_at, NEW.reopen_reason)
                     IS DISTINCT FROM (OLD.reopen_requested_by, OLD.reopen_requested_at, OLD.reopen_reason) THEN
                RAISE EXCEPTION 'a reopen request is made on a closed period only' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'OPEN' AND NEW.status = 'CLOSING' THEN
                IF NEW.close_requested_by IS DISTINCT FROM a OR NEW.close_requested_at IS NULL
                   OR (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a close is requested by the signed actor' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'CLOSED' THEN
                IF NEW.closed_by IS DISTINCT FROM a OR a = OLD.close_requested_by OR NEW.closed_at IS NULL
                   OR (NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                      IS DISTINCT FROM (OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a period is closed by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'OPEN' THEN
                -- close request withdrawn or turned down: the request goes, the history stays
                IF (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'an open period keeps its history' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'CLOSED' THEN
                -- a reopen request by the signed actor, once; or its rejection or withdrawal, which clears it (D-102; the
                -- audit keeps it); nothing else of a closed period changes
                IF (NEW.closed_by, NEW.closed_at, NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                   IS DISTINCT FROM (OLD.closed_by, OLD.closed_at, OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at)
                   OR NOT ((OLD.reopen_requested_by IS NULL AND NEW.reopen_requested_by = a AND NEW.reopen_requested_at IS NOT NULL)
                           OR (OLD.reopen_requested_by IS NOT NULL AND NEW.reopen_requested_by IS NULL)) THEN
                  RAISE EXCEPTION 'a closed period changes only by a reopen request, its rejection or its withdrawal' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'OPEN' THEN
                IF OLD.reopen_requested_by IS NULL OR NEW.reopened_by IS DISTINCT FROM a OR a = OLD.reopen_requested_by OR NEW.reopened_at IS NULL THEN
                  RAISE EXCEPTION 'a closed period reopens on a request decided by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a period goes OPEN → CLOSING → CLOSED' USING ERRCODE = '23514';
              ELSE
                -- same status (OPEN or CLOSING): nothing of the lifecycle changes in place
                RAISE EXCEPTION 'a period changes only by its lifecycle' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION eora_guard_inventory_period() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'OPEN' OR NEW.closed_by IS NOT NULL OR NEW.reopened_by IS NOT NULL OR NEW.reopen_requested_by IS NOT NULL THEN
                  RAISE EXCEPTION 'a period starts open' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.tenant_id, NEW.plant_id, NEW.period) IS DISTINCT FROM (OLD.tenant_id, OLD.plant_id, OLD.period) THEN
                RAISE EXCEPTION 'a period is fixed' USING ERRCODE = '23514';
              END IF;
              -- every update is one of the transitions below; each pins the history it does not write. A reopen request is
              -- written only by the CLOSED → CLOSED request and cleared only by the CLOSED → OPEN decision.
              IF NOT (OLD.status = 'CLOSED' AND NEW.status IN ('CLOSED', 'OPEN'))
                 AND (NEW.reopen_requested_by, NEW.reopen_requested_at, NEW.reopen_reason)
                     IS DISTINCT FROM (OLD.reopen_requested_by, OLD.reopen_requested_at, OLD.reopen_reason) THEN
                RAISE EXCEPTION 'a reopen request is made on a closed period only' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'OPEN' AND NEW.status = 'CLOSING' THEN
                IF NEW.close_requested_by IS DISTINCT FROM a OR NEW.close_requested_at IS NULL
                   OR (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a close is requested by the signed actor' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'CLOSED' THEN
                IF NEW.closed_by IS DISTINCT FROM a OR a = OLD.close_requested_by OR NEW.closed_at IS NULL
                   OR (NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                      IS DISTINCT FROM (OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'a period is closed by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF OLD.status = 'CLOSING' AND NEW.status = 'OPEN' THEN
                -- close request withdrawn or turned down: the request goes, the history stays
                IF (NEW.reopened_by, NEW.reopened_at) IS DISTINCT FROM (OLD.reopened_by, OLD.reopened_at) THEN
                  RAISE EXCEPTION 'an open period keeps its history' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'CLOSED' THEN
                -- the reopen request: by the signed actor, once; nothing else of a closed period changes
                IF NOT (OLD.reopen_requested_by IS NULL AND NEW.reopen_requested_by = a AND NEW.reopen_requested_at IS NOT NULL
                        AND (NEW.closed_by, NEW.closed_at, NEW.close_requested_by, NEW.close_requested_at, NEW.reopened_by, NEW.reopened_at)
                            IS NOT DISTINCT FROM (OLD.closed_by, OLD.closed_at, OLD.close_requested_by, OLD.close_requested_at, OLD.reopened_by, OLD.reopened_at)) THEN
                  RAISE EXCEPTION 'a closed period changes only by a reopen request' USING ERRCODE = '23514';
                END IF;
              ELSIF OLD.status = 'CLOSED' AND NEW.status = 'OPEN' THEN
                IF OLD.reopen_requested_by IS NULL OR NEW.reopened_by IS DISTINCT FROM a OR a = OLD.reopen_requested_by OR NEW.reopened_at IS NULL THEN
                  RAISE EXCEPTION 'a closed period reopens on a request decided by a second person' USING ERRCODE = '42501';
                END IF;
              ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'a period goes OPEN → CLOSING → CLOSED' USING ERRCODE = '23514';
              ELSE
                -- same status (OPEN or CLOSING): nothing of the lifecycle changes in place
                RAISE EXCEPTION 'a period changes only by its lifecycle' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
SQL);
        DB::delete('delete from role_template_permissions where permission_code = ?', [self::CODE]);
        try {
            DB::transaction(fn () => DB::delete('delete from permissions where code = ?', [self::CODE]));
        } catch (QueryException $e) {
            // still held by a tenant role: the catalogue entry stays (as migration 002300)
            if ($e->getCode() !== '23503') {
                throw $e;
            }
        }
    }
};
