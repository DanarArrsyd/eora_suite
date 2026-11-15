<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.mps_versions` — master production schedules per plant (doc 34 §1.3, D-123, BR-PLN-04): status, horizon,
 * items, planned quantity and who calculated, submitted and approved it. Read with `planning.mps.view`.
 */
final class MpsVersionsQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'SUPERSEDED', 'CANCELLED'];

    public const array STATUS_LABELS = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED' => 'Approved', 'SUPERSEDED' => 'Superseded', 'CANCELLED' => 'Cancelled'];

    public function resource(): string
    {
        return 'planning.mps_versions';
    }

    public function permission(): string
    {
        return 'planning.mps.view';
    }

    public function from(): string
    {
        return 'mps_versions m
                 join plants p on p.tenant_id = m.tenant_id and p.id = m.plant_id
                 cross join lateral (
                   select (select count(*) from mps_items i where i.tenant_id = m.tenant_id and i.mps_version_id = m.id) as items,
                          (select count(*) from mps_planned_orders o where o.tenant_id = m.tenant_id and o.mps_version_id = m.id) as orders,
                          (select coalesce(sum(o.qty), 0) from mps_planned_orders o where o.tenant_id = m.tenant_id and o.mps_version_id = m.id) as planned,
                          (select count(*) from mps_planned_orders o where o.tenant_id = m.tenant_id and o.mps_version_id = m.id and o.adjusted_at is not null) as adjusted,
                          (select count(*) from mps_capacity c where c.tenant_id = m.tenant_id and c.mps_version_id = m.id and c.status = \'OVERLOAD\') as overloads) x
                 left join users uc on uc.id = m.calculated_by
                 left join users us on us.id = m.submitted_by
                 left join users ua on ua.id = m.approved_by';
    }

    public function idColumn(): string
    {
        return 'm.id';
    }

    public function plantColumn(): string
    {
        return 'm.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'm.id', Field::UUID, filterable: true),
            new Field('code', 'm.mps_no', sortable: true, search: 'prefix'),
            new Field('status', 'm.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'm.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('horizonStart', 'm.horizon_start::text', sortable: true),
            new Field('horizonEnd', '(m.horizon_start + 7 * m.horizon_weeks - 1)::text'),
            new Field('horizonWeeks', 'm.horizon_weeks', Field::INTEGER),
            new Field('planDate', 'm.plan_date::text'),
            new Field('itemCount', 'x.items', Field::INTEGER),
            new Field('plannedOrderCount', 'x.orders', Field::INTEGER),
            new Field('plannedQty', 'trim_scale(x.planned)::text'),
            new Field('adjustedCount', 'x.adjusted', Field::INTEGER),
            new Field('overloadCount', 'x.overloads', Field::INTEGER),
            new Field('note', 'm.note'),
            new Field('returnReason', 'm.return_reason'),
            new Field('calculatedAt', 'm.calculated_at', Field::INSTANT, sortable: true),
            new Field('calculatedById', 'm.calculated_by', Field::UUID),
            new Field('calculatedByName', 'uc.display_name'),
            new Field('submittedAt', 'm.submitted_at', Field::INSTANT),
            new Field('submittedByName', 'us.display_name'),
            new Field('approvedAt', 'm.approved_at', Field::INSTANT, sortable: true),
            new Field('approvedByName', 'ua.display_name'),
            new Field('createdAt', 'm.created_at', Field::INSTANT, sortable: true),
            new Field('version', 'm.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Master production schedules';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('MPS', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('From week', 'horizonStart', ExportColumn::DATE),
            new ExportColumn('To', 'horizonEnd', ExportColumn::DATE),
            new ExportColumn('Items', 'itemCount'),
            new ExportColumn('Planned orders', 'plannedOrderCount'),
            new ExportColumn('Planned qty', 'plannedQty', ExportColumn::DECIMAL),
            new ExportColumn('Adjusted', 'adjustedCount'),
            new ExportColumn('Overloaded weeks', 'overloadCount'),
            new ExportColumn('Calculated by', 'calculatedByName'),
            new ExportColumn('Approved by', 'approvedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
