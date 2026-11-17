<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-129 (Codex on #93): an approval request is a task only for the submission it was sent for. A document returned and
 * submitted again sends a new request; the earlier one stays in the inbox as history but no longer counts as action
 * required. `subject_since` holds when the document was submitted (or the month close/reopen asked for), and
 * `eora_approval_waiting(kind, id, since)` is true only while the document still waits from that same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inbox_notifications ADD COLUMN subject_since timestamptz;
            DROP FUNCTION IF EXISTS public.eora_approval_waiting(text, uuid);

            CREATE OR REPLACE FUNCTION public.eora_approval_waiting(p_kind text, p_id uuid, p_since timestamptz) RETURNS boolean
              LANGUAGE sql STABLE SET search_path = '' AS $fn$
              SELECT p_since IS NOT NULL AND CASE p_kind
                WHEN 'routing_version' THEN EXISTS (SELECT 1 FROM public.routing_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'bom_version' THEN EXISTS (SELECT 1 FROM public.bom_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'bom_routing_map' THEN EXISTS (SELECT 1 FROM public.bom_routing_maps v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'item_weight' THEN EXISTS (SELECT 1 FROM public.item_weights v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'conversion_profile_version' THEN EXISTS (SELECT 1 FROM public.conversion_profile_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'mps_version' THEN EXISTS (SELECT 1 FROM public.mps_versions v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'work_order' THEN EXISTS (SELECT 1 FROM public.work_orders v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'inventory_document' THEN EXISTS (SELECT 1 FROM public.inventory_documents v WHERE v.id = p_id AND v.status = 'SUBMITTED' AND v.submitted_at = p_since)
                WHEN 'period_close' THEN EXISTS (SELECT 1 FROM public.inventory_periods v WHERE v.id = p_id AND v.status = 'CLOSING' AND v.close_requested_at = p_since)
                WHEN 'period_reopen' THEN EXISTS (SELECT 1 FROM public.inventory_periods v WHERE v.id = p_id AND v.status = 'CLOSED' AND v.reopen_requested_at = p_since)
                ELSE false
              END
            $fn$;
            REVOKE ALL ON FUNCTION public.eora_approval_waiting(text, uuid, timestamptz) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.eora_approval_waiting(text, uuid, timestamptz) TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.eora_approval_waiting(text, uuid, timestamptz);
            ALTER TABLE inbox_notifications DROP COLUMN IF EXISTS subject_since;
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
};
