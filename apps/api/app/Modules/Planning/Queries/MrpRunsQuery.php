<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.mrp_runs` — MRP runs per plant (doc 34 §1.4, D-124): the approved MPS they came from, planned work orders and
 * material shortages, who ran them. Read with `planning.mrp.view`.
 */
final class MrpRunsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.mrp_runs';
    }

    public function permission(): string
    {
        return 'planning.mrp.view';
    }

    public function from(): string
    {
        return "mrp_runs r
                 join plants p on p.tenant_id = r.tenant_id and p.id = r.plant_id
                 join mps_versions m on m.tenant_id = r.tenant_id and m.id = r.mps_version_id
                 cross join lateral (
                   select count(*) filter (where o.kind = 'MAKE') as make, count(*) filter (where o.kind = 'BUY') as buy,
                          count(distinct o.mrp_item_id) filter (where o.kind = 'BUY') as short_items
                     from mrp_orders o where o.tenant_id = r.tenant_id and o.mrp_run_id = r.id) x
                 left join users u on u.id = r.created_by";
    }

    public function idColumn(): string
    {
        return 'r.id';
    }

    public function plantColumn(): string
    {
        return 'r.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'r.id', Field::UUID, filterable: true),
            new Field('code', 'r.run_no', sortable: true, search: 'prefix'),
            new Field('plantId', 'r.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('mpsVersionId', 'r.mps_version_id', Field::UUID, filterable: true),
            new Field('mpsNo', 'm.mps_no'),
            new Field('mpsStatus', 'm.status'),
            new Field('planDate', 'r.plan_date::text'),
            new Field('horizonStart', 'r.horizon_start::text'),
            new Field('horizonEnd', '(r.horizon_start + 7 * r.horizon_weeks - 1)::text'),
            new Field('plannedOrderCount', 'x.make', Field::INTEGER),
            new Field('shortageCount', 'x.buy', Field::INTEGER),
            new Field('shortItemCount', 'x.short_items', Field::INTEGER),
            new Field('createdAt', 'r.created_at', Field::INSTANT, sortable: true),
            new Field('createdByName', 'u.display_name'),
            new Field('version', 'r.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'MRP runs';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('MRP run', 'code'),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('MPS', 'mpsNo'),
            new ExportColumn('Plan date', 'planDate', ExportColumn::DATE),
            new ExportColumn('Planned work orders', 'plannedOrderCount'),
            new ExportColumn('Shortage lines', 'shortageCount'),
            new ExportColumn('Materials short', 'shortItemCount'),
            new ExportColumn('Run by', 'createdByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
