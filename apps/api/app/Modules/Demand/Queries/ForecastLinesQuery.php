<?php

declare(strict_types=1);

namespace App\Modules\Demand\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `demand.forecast_lines` — forecast per customer part and ISO week with what firm demand consumed of it (BR-DEM-08,
 * D-040, D-122): firm = required quantity of the schedule lines of that customer part and plant whose requirement date
 * falls in the week (not DRAFT, SUPERSEDED or CANCELLED; fulfilled included, so a delivery never re-opens forecast);
 * net forecast = max(0, forecast − firm). Read with `demand.forecast.view`.
 */
final class ForecastLinesQuery extends QueryDefinition
{
    /**
     * Firm demand matched to a forecast line: same customer part (so the same customer and item), plant and week. A
     * version has one line per customer part and week, so each firm quantity consumes one forecast line only. A revised
     * (superseded) schedule line still consumes what it delivered, never its old outstanding (BR-DEM-15, D-122).
     */
    public const string FIRM = "coalesce((select sum(case when s.status = 'SUPERSEDED'
                                                     then coalesce((select sum(m.qty_signed) from inventory_movements m
                                                                      join locations ml on ml.tenant_id = m.tenant_id and ml.id = m.location_id and ml.location_type = 'V_CUSTOMER'
                                                                     where m.tenant_id = s.tenant_id and m.schedule_line_id = s.id), 0)
                                                     else s.required_qty end)
                                              from delivery_schedule_lines s
                                             join sales_order_lines ol on ol.tenant_id = s.tenant_id and ol.id = s.so_line_id
                                            where s.tenant_id = l.tenant_id and s.plant_id = l.plant_id and ol.customer_id = l.customer_id and ol.customer_item_id = l.customer_item_id
                                              and s.status in ('FIRM', 'PARTIALLY_FULFILLED', 'FULFILLED', 'SUPERSEDED')
                                              and s.requirement_date between l.week_start and l.week_start + 6), 0)";

    public function resource(): string
    {
        return 'demand.forecast_lines';
    }

    public function permission(): string
    {
        return 'demand.forecast.view';
    }

    public function from(): string
    {
        return 'forecast_lines l
                 join forecast_versions f on f.tenant_id = l.tenant_id and f.id = l.forecast_version_id
                 join plants p on p.tenant_id = l.tenant_id and p.id = l.plant_id
                 join customers c on c.tenant_id = l.tenant_id and c.id = l.customer_id
                 join customer_items ci on ci.tenant_id = l.tenant_id and ci.id = l.customer_item_id
                 join items i on i.tenant_id = l.tenant_id and i.id = l.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 cross join lateral (select '.self::FIRM.' as firm) fm';
    }

    public function idColumn(): string
    {
        return 'l.id';
    }

    public function plantColumn(): string
    {
        return 'l.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'l.id', Field::UUID, filterable: true),
            new Field('code', 'ci.customer_part_no', search: 'prefix'),
            new Field('forecastVersionId', 'l.forecast_version_id', Field::UUID, filterable: true),
            new Field('forecastNo', 'f.forecast_no'),
            new Field('versionStatus', 'f.status', Field::ENUM, filterable: true, options: ForecastVersionsQuery::STATUSES),
            new Field('plantId', 'l.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('customerId', 'l.customer_id', Field::UUID, filterable: true),
            new Field('customerCode', 'c.code', sortable: true),
            new Field('customerItemId', 'l.customer_item_id', Field::UUID),
            new Field('itemId', 'l.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name'),
            new Field('weekStart', 'l.week_start::text', sortable: true),
            new Field('qty', 'trim_scale(l.qty)::text'),
            new Field('firmQty', 'trim_scale(fm.firm)::text'),
            new Field('netQty', 'trim_scale(greatest(l.qty - fm.firm, 0))::text'),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('version', 'l.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Forecast lines';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('Forecast', 'forecastNo'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('Customer part', 'code'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Week', 'weekStart'),
            new ExportColumn('Forecast qty', 'qty'),
            new ExportColumn('Firm', 'firmQty'),
            new ExportColumn('Net forecast', 'netQty'),
            new ExportColumn('Unit', 'uomCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['weekStart', false], ['itemCode', false]];
    }
}
