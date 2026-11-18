<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array PATCHES = [
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND l.location_type = 'QC_AREA' AND m.stock_status = CASE WHEN r.inspection_required THEN 'PENDING_QC' ELSE 'QC_RELEASED' END",
            "m.item_id = r.item_id AND CASE
                            WHEN mi.item_type = 'SEMI_FINISHED' AND NOT r.inspection_required THEN
                              m.lot_id IS NOT NULL AND l.location_type = 'RM_WAREHOUSE' AND m.stock_status = 'UNRESTRICTED'
                            ELSE (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL)
                              AND l.location_type = 'QC_AREA' AND m.stock_status = CASE WHEN r.inspection_required THEN 'PENDING_QC' ELSE 'QC_RELEASED' END
                          END", ],
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'",
            "m.item_id = r.item_id AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REWORK'"],
        ['eora_check_production_posting()',
            "m.item_id = r.item_id AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'",
            "m.item_id = r.item_id AND (mi.item_type <> 'SEMI_FINISHED' OR m.lot_id IS NOT NULL) AND l.location_type = 'NG_AREA' AND m.stock_status = 'REJECTED'"],
        ['eora_check_qc_disposition()',
            "l.location_type = 'QC_AREA' AND m.stock_status = 'QC_RELEASED'",
            "CASE WHEN EXISTS (SELECT 1 FROM public.items i WHERE i.tenant_id = m.tenant_id AND i.id = m.item_id AND i.item_type = 'SEMI_FINISHED')
                               THEN m.lot_id IS NOT NULL AND l.location_type = 'RM_WAREHOUSE' AND m.stock_status = 'UNRESTRICTED'
                               ELSE l.location_type = 'QC_AREA' AND m.stock_status = 'QC_RELEASED' END", ],
    ];

    public function up(): void
    {
        $this->patch(false);
    }

    public function down(): void
    {
        $this->patch(true);
    }

    /** Replace only the output destination rules. The existing trigger and all other posting checks stay in force. */
    private function patch(bool $back): void
    {
        $definitions = [];
        foreach (self::PATCHES as [$function, $from, $to]) {
            [$find, $replace] = $back ? [$to, $from] : [$from, $to];
            $definitions[$function] ??= (string) DB::selectOne('select pg_get_functiondef(?::regprocedure) as d', [$function])->d;
            if (substr_count($definitions[$function], $find) !== 1) {
                throw new RuntimeException("Could not identify the output rule in {$function}.");
            }
            $definitions[$function] = str_replace($find, $replace, $definitions[$function]);
        }
        foreach ($definitions as $definition) {
            // PostgreSQL supplies these definitions, patched only with the constants above.
            DB::connection()->getPdo()->exec($definition);
        }
    }
};
