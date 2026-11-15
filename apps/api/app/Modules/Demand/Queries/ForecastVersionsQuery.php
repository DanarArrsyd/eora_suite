<?php

declare(strict_types=1);

namespace App\Modules\Demand\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `demand.forecast_versions` — forecast versions per customer + plant (doc 34 §1.2, D-122, D-040): status, lines, total
 * forecast and what firm demand consumed of it. Read with `demand.forecast.view` inside the plants of that scope.
 */
final class ForecastVersionsQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'ACTIVE', 'SUPERSEDED'];

    public const array STATUS_LABELS = ['DRAFT' => 'Draft', 'ACTIVE' => 'Active', 'SUPERSEDED' => 'Superseded'];

    public function resource(): string
    {
        return 'demand.forecast_versions';
    }

    public function permission(): string
    {
        return 'demand.forecast.view';
    }

    public function from(): string
    {
        return 'forecast_versions f
                 join plants p on p.tenant_id = f.tenant_id and p.id = f.plant_id
                 join customers c on c.tenant_id = f.tenant_id and c.id = f.customer_id
                 cross join lateral (
                   select count(*) as lines, coalesce(sum(l.qty), 0) as total, min(l.week_start) as first_week, max(l.week_start) as last_week
                     from forecast_lines l where l.tenant_id = f.tenant_id and l.forecast_version_id = f.id) x
                 left join users ua on ua.id = f.activated_by
                 left join users ub on ub.id = f.created_by';
    }

    public function idColumn(): string
    {
        return 'f.id';
    }

    public function plantColumn(): string
    {
        return 'f.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'f.id', Field::UUID, filterable: true),
            new Field('code', 'f.forecast_no', sortable: true, search: 'prefix'),
            new Field('status', 'f.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'f.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('customerId', 'f.customer_id', Field::UUID, filterable: true),
            new Field('customerCode', 'c.code', sortable: true, search: 'prefix'),
            new Field('customerName', 'c.name'),
            new Field('lineCount', 'x.lines', Field::INTEGER),
            new Field('totalQty', 'trim_scale(x.total)::text'),
            new Field('firstWeek', 'x.first_week::text'),
            new Field('lastWeek', 'x.last_week::text'),
            new Field('note', 'f.note'),
            new Field('activatedAt', 'f.activated_at', Field::INSTANT, sortable: true),
            new Field('activatedByName', 'ua.display_name'),
            new Field('createdAt', 'f.created_at', Field::INSTANT, sortable: true),
            new Field('createdByName', 'ub.display_name'),
            new Field('version', 'f.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Forecasts';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('Forecast', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('Lines', 'lineCount'),
            new ExportColumn('Total', 'totalQty'),
            new ExportColumn('From week', 'firstWeek'),
            new ExportColumn('To week', 'lastWeek'),
            new ExportColumn('Activated by', 'activatedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
