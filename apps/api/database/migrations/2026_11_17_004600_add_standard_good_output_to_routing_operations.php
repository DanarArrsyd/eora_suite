<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE routing_operations
              ALTER COLUMN std_cycle_seconds DROP NOT NULL,
              ADD COLUMN std_good_output_per_hour numeric(11,3),
              ADD CONSTRAINT routing_operations_capacity_basis_check CHECK (
                  (std_cycle_seconds IS NOT NULL AND std_good_output_per_hour IS NULL)
                  OR (std_cycle_seconds IS NULL AND std_good_output_per_hour IS NOT NULL
                      AND std_good_output_per_hour > 0 AND std_good_output_per_hour < 100000000)
              );

            CREATE OR REPLACE FUNCTION eora_check_routing_good_output_unit() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (
                  SELECT 1 FROM public.routing_versions v
                  JOIN public.routings r ON (r.tenant_id, r.id) = (v.tenant_id, v.routing_id)
                  JOIN public.items i ON (i.tenant_id, i.id) = (r.tenant_id, r.item_id)
                  JOIN public.uoms u ON (u.tenant_id, u.id) = (i.tenant_id, i.base_uom_id)
                  WHERE (v.tenant_id, v.id) = (NEW.tenant_id, NEW.routing_version_id) AND u.code = 'PCS'
              ) THEN
                RAISE EXCEPTION 'good pieces per hour requires an item with base unit PCS' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_routing_good_output_unit() FROM PUBLIC;
            CREATE TRIGGER check_good_output_unit BEFORE INSERT OR UPDATE ON routing_operations
              FOR EACH ROW WHEN (NEW.std_good_output_per_hour IS NOT NULL)
              EXECUTE FUNCTION eora_check_routing_good_output_unit();
            SQL);
    }

    public function down(): void
    {
        // PostgreSQL checks all rows before restoring NOT NULL. A rate-only operation has no legacy cycle to restore.
        DB::unprepared(<<<'SQL'
            ALTER TABLE routing_operations ALTER COLUMN std_cycle_seconds SET NOT NULL;
            DROP TRIGGER check_good_output_unit ON routing_operations;
            DROP FUNCTION eora_check_routing_good_output_unit();
            ALTER TABLE routing_operations
              DROP CONSTRAINT routing_operations_capacity_basis_check,
              DROP COLUMN std_good_output_per_hour;
            SQL);
    }
};
