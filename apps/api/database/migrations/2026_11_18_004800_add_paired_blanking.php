<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array PATCHES = [
        ['eora_guard_routing_version()',
            'IF OLD.status IN (\'APPROVED\', \'SUPERSEDED\') THEN',
            "IF (OLD.status <> 'DRAFT' OR NEW.status <> 'DRAFT') AND (NEW.paired_item_id, NEW.pairs_per_sheet) IS DISTINCT FROM (OLD.paired_item_id, OLD.pairs_per_sheet) THEN
                  RAISE EXCEPTION 'only a draft routing can change its paired output' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED' AND NEW.paired_item_id IS NOT NULL THEN
                  IF NOT EXISTS (SELECT 1 FROM public.routing_operations o WHERE o.routing_version_id = NEW.id
                                 HAVING count(*) = 1 AND count(o.std_good_output_per_hour) = 1) THEN
                    RAISE EXCEPTION 'paired blanking needs one operation with a combined good rate' USING ERRCODE = '23514';
                  END IF;
                END IF;
                IF OLD.status IN ('APPROVED', 'SUPERSEDED') THEN", ],
        ['eora_guard_work_order()',
            '-- BR-PLN-01: the snapshot is the approved BOM and primary routing (and validated net weight) in force today at the plant',
            "IF EXISTS (SELECT 1 FROM public.routing_versions v WHERE v.id = NEW.routing_version_id AND v.paired_item_id IS NOT NULL AND (
                     mod(NEW.qty, v.pairs_per_sheet) <> 0
                     OR (SELECT count(*) FROM public.routing_operations o WHERE o.routing_version_id = v.id AND o.std_good_output_per_hour IS NOT NULL) <> 1
                     OR (SELECT count(*) FROM public.routing_operations o WHERE o.routing_version_id = v.id) <> 1
                     OR (SELECT count(*) FROM public.bom_lines l WHERE l.bom_version_id = NEW.bom_version_id) <> 1
                     OR NOT EXISTS (SELECT 1 FROM public.bom_lines l JOIN public.items i ON i.tenant_id = l.tenant_id AND i.id = l.component_item_id
                       JOIN public.uoms u ON u.tenant_id = l.tenant_id AND u.id = l.uom_id
                       WHERE l.bom_version_id = NEW.bom_version_id AND i.base_uom_id = u.id AND u.code = 'SHEET' AND i.item_type = 'RAW'))) THEN
                    RAISE EXCEPTION 'paired blanking needs whole sheets, one operation and one SHEET BOM line' USING ERRCODE = '23514';
                  END IF;
                  IF EXISTS (SELECT 1 FROM public.mrp_orders o WHERE o.id = NEW.mrp_order_id AND o.paired_routing_version_id IS NOT NULL
                     AND o.paired_routing_version_id IS DISTINCT FROM NEW.routing_version_id) THEN
                    RAISE EXCEPTION 'the planned paired routing changed; rerun MRP' USING ERRCODE = '23514';
                  END IF;
                  -- BR-PLN-01: the snapshot is the approved BOM and primary routing (and validated net weight) in force today at the plant", ],
        ['eora_guard_work_order_material()',
            'NEW.required_qty = pg_catalog.round(w.qty * l.gross_qty_per, 6)',
            'NEW.required_qty = CASE WHEN v.pairs_per_sheet IS NULL THEN pg_catalog.round(w.qty * l.gross_qty_per, 6) ELSE w.qty / v.pairs_per_sheet END'],
        ['eora_guard_work_order_material()',
            'WHERE w.tenant_id = NEW.tenant_id AND w.id = NEW.work_order_id AND w.status = \'RELEASED\' AND w.released_at = pg_catalog.now()',
            "JOIN public.routing_versions v ON v.tenant_id = w.tenant_id AND v.id = w.routing_version_id
                 WHERE w.tenant_id = NEW.tenant_id AND w.id = NEW.work_order_id AND w.status = 'RELEASED' AND w.released_at = pg_catalog.now()", ],
        ['eora_check_production_posting()',
            'wo.item_id, i.inspection_required INTO r',
            'wo.item_id, i.inspection_required, v.paired_item_id INTO r'],
        ['eora_check_production_posting()',
            'JOIN public.items i ON i.tenant_id = wo.tenant_id AND i.id = wo.item_id',
            'JOIN public.items i ON i.tenant_id = wo.tenant_id AND i.id = wo.item_id
                JOIN public.routing_versions v ON v.tenant_id = wo.tenant_id AND v.id = wo.routing_version_id', ],
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND CASE
                            WHEN mi.item_type = 'SEMI_FINISHED' AND NOT r.inspection_required THEN",
            "(m.item_id = r.item_id OR m.item_id IS NOT DISTINCT FROM r.paired_item_id) AND CASE
                            WHEN mi.item_type = 'SEMI_FINISHED' AND NOT mi.inspection_required THEN", ],
        ['eora_check_production_posting()',
            "AND l.location_type = 'QC_AREA' AND m.stock_status = CASE WHEN r.inspection_required THEN 'PENDING_QC' ELSE 'QC_RELEASED' END",
            "AND l.location_type = 'QC_AREA' AND m.stock_status = CASE WHEN mi.inspection_required THEN 'PENDING_QC' ELSE 'QC_RELEASED' END"],
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'",
            "(m.item_id = r.item_id OR m.item_id IS NOT DISTINCT FROM r.paired_item_id) AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'"],
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'",
            "(m.item_id = r.item_id OR m.item_id IS NOT DISTINCT FROM r.paired_item_id) AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'"],
        ['eora_check_production_posting()',
            "OR (SELECT count(DISTINCT m.lot_id) FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
           AND m.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT')) > 1",
            "OR EXISTS (SELECT 1 FROM public.inventory_movements m WHERE m.tenant_id = NEW.tenant_id AND m.document_id = NEW.id
                         AND m.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT')
                         GROUP BY m.item_id HAVING count(DISTINCT m.lot_id) > 1)", ],
        ['eora_check_production_posting()',
            "SELECT o.lot_id FROM public.inventory_movements o WHERE o.tenant_id = NEW.tenant_id AND o.document_id = NEW.id
               AND o.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') AND o.lot_id IS NOT NULL LIMIT 1",
            "SELECT DISTINCT o.lot_id FROM public.inventory_movements o WHERE o.tenant_id = NEW.tenant_id AND o.document_id = NEW.id
               AND o.movement_code IN ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') AND o.lot_id IS NOT NULL", ],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE routing_versions ADD COLUMN paired_item_id uuid, ADD COLUMN pairs_per_sheet integer,
              ADD CHECK ((paired_item_id IS NULL AND pairs_per_sheet IS NULL)
                         OR (paired_item_id IS NOT NULL AND pairs_per_sheet IS NOT NULL AND pairs_per_sheet BETWEEN 1 AND 100000)),
              ADD FOREIGN KEY (tenant_id, paired_item_id) REFERENCES items (tenant_id, id);
            GRANT UPDATE (paired_item_id, pairs_per_sheet) ON routing_versions TO eora_app;

            ALTER TABLE mrp_orders ADD COLUMN paired_routing_version_id uuid,
              ADD FOREIGN KEY (tenant_id, paired_routing_version_id) REFERENCES routing_versions (tenant_id, id),
              ADD CHECK (paired_routing_version_id IS NULL OR kind = 'MAKE');

            CREATE OR REPLACE FUNCTION eora_check_paired_routing() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM public.routings r
                JOIN public.items i ON (i.tenant_id, i.id) = (r.tenant_id, r.item_id)
                JOIN public.items p ON (p.tenant_id, p.id) = (r.tenant_id, NEW.paired_item_id)
                JOIN public.uoms u ON (u.tenant_id, u.id) = (i.tenant_id, i.base_uom_id)
                WHERE (r.tenant_id, r.id) = (NEW.tenant_id, NEW.routing_id) AND i.id <> p.id
                  AND r.routing_kind = 'PRIMARY' AND u.code = 'PCS' AND p.base_uom_id = i.base_uom_id
                  AND i.item_type = 'SEMI_FINISHED' AND p.item_type = 'SEMI_FINISHED' AND i.lot_controlled AND p.lot_controlled
              ) THEN
                RAISE EXCEPTION 'paired routing requires two distinct lot-controlled semi-finished PCS items' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_paired_routing() FROM PUBLIC;
            CREATE TRIGGER check_paired_routing BEFORE INSERT OR UPDATE OF paired_item_id, pairs_per_sheet ON routing_versions
              FOR EACH ROW WHEN (NEW.paired_item_id IS NOT NULL) EXECUTE FUNCTION eora_check_paired_routing();
            SQL);
        $this->patch(false);
    }

    public function down(): void
    {
        // Keep deployed routing and work order history interpretable once a paired recipe is in use.
        DB::unprepared('ALTER TABLE routing_versions ADD CONSTRAINT no_paired_routing_on_downgrade CHECK (paired_item_id IS NULL)');
        $this->patch(true);
        DB::unprepared(<<<'SQL'
            DROP TRIGGER check_paired_routing ON routing_versions;
            DROP FUNCTION eora_check_paired_routing();
            ALTER TABLE mrp_orders DROP COLUMN paired_routing_version_id;
            ALTER TABLE routing_versions DROP COLUMN paired_item_id, DROP COLUMN pairs_per_sheet;
            SQL);
    }

    private function patch(bool $back): void
    {
        $definitions = [];
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definitions[$function] ??= (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definitions[$function], $find) !== 1) {
                throw new RuntimeException("Could not locate the paired-output rule in {$function}.");
            }
            $definitions[$function] = str_replace($find, $replace, $definitions[$function]);
        }
        foreach ($definitions as $definition) {
            DB::connection()->getPdo()->exec($definition);
        }
    }
};
