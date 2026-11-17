<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-129: approval requests reach the people who may decide them, and the requester hears the decision.
 *
 * - `inbox_notifications.subject_kind / subject_id` name the document an approval notification is about. Its
 *   "action required" lasts only while that document still waits for a decision, so approvers who did not decide it are
 *   not left with a stale task once someone else has (D-025 kept for every other notification).
 * - `eora_approval_waiting(kind, id)` says whether a document still waits; it runs as the caller under row-level
 *   security (no definer rights), so it only sees documents of the caller's tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inbox_notifications ADD COLUMN subject_kind text, ADD COLUMN subject_id uuid;
            ALTER TABLE inbox_notifications ADD CONSTRAINT inbox_notifications_subject_check
              CHECK ((subject_kind IS NULL) = (subject_id IS NULL) AND (subject_kind IS NULL OR subject_kind ~ '^[a-z][a-z_]*$' AND length(subject_kind) <= 40));

            CREATE OR REPLACE FUNCTION public.eora_approval_waiting(p_kind text, p_id uuid) RETURNS boolean
              LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT CASE p_kind
                WHEN 'routing_version' THEN EXISTS (SELECT 1 FROM public.routing_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'bom_version' THEN EXISTS (SELECT 1 FROM public.bom_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'bom_routing_map' THEN EXISTS (SELECT 1 FROM public.bom_routing_maps v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'item_weight' THEN EXISTS (SELECT 1 FROM public.item_weights v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'conversion_profile_version' THEN EXISTS (SELECT 1 FROM public.conversion_profile_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'mps_version' THEN EXISTS (SELECT 1 FROM public.mps_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'work_order' THEN EXISTS (SELECT 1 FROM public.work_orders v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'inventory_document' THEN EXISTS (SELECT 1 FROM public.inventory_documents v WHERE v.id = p_id AND v.status = 'SUBMITTED')
                WHEN 'period_close' THEN EXISTS (SELECT 1 FROM public.inventory_periods v WHERE v.id = p_id AND v.status = 'CLOSING')
                WHEN 'period_reopen' THEN EXISTS (SELECT 1 FROM public.inventory_periods v WHERE v.id = p_id AND v.status = 'CLOSED' AND v.reopen_requested_by IS NOT NULL)
                ELSE false
              END
            $fn$;
            REVOKE ALL ON FUNCTION public.eora_approval_waiting(text, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.eora_approval_waiting(text, uuid) TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.eora_approval_waiting(text, uuid);
            ALTER TABLE inbox_notifications DROP CONSTRAINT IF EXISTS inbox_notifications_subject_check;
            ALTER TABLE inbox_notifications DROP COLUMN IF EXISTS subject_id, DROP COLUMN IF EXISTS subject_kind;
            SQL);
    }
};
