<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.plan_parameters` — planning parameters per item + plant (doc 34 §1.1, D-121): lead times in plant working
 * days, safety stock in the item's base unit (empty = NOT CONFIGURED, D-046), lot policy. Read with
 * `planning.plan_parameter.view` inside the plants of that scope.
 */
final class PlanParametersQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.plan_parameters';
    }

    public function permission(): string
    {
        return 'planning.plan_parameter.view';
    }

    public function from(): string
    {
        return 'item_plan_parameters pp
                 join plants p on p.tenant_id = pp.tenant_id and p.id = pp.plant_id
                 join items i on i.tenant_id = pp.tenant_id and i.id = pp.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 left join users uu on uu.id = pp.updated_by';
    }

    public function idColumn(): string
    {
        return 'pp.id';
    }

    public function plantColumn(): string
    {
        return 'pp.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'pp.id', Field::UUID, filterable: true),
            new Field('code', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemId', 'i.id', Field::UUID, filterable: true),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('itemType', 'i.item_type', Field::ENUM, filterable: true, options: ['RAW', 'CONSUMABLE', 'SEMI_FINISHED', 'FINISHED', 'SCRAP']),
            new Field('plantId', 'pp.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('productionLeadTimeDays', 'pp.production_lead_time_days', Field::INTEGER),
            new Field('purchaseLeadTimeDays', 'pp.purchase_lead_time_days', Field::INTEGER),
            new Field('safetyStock', 'trim_scale(pp.safety_stock)::text'),
            new Field('safetyStockConfigured', 'pp.safety_stock is not null', Field::BOOLEAN, filterable: true),
            new Field('lotPolicy', 'pp.lot_policy'),
            new Field('updatedAt', 'pp.updated_at', Field::INSTANT, sortable: true),
            new Field('updatedByName', 'uu.display_name'),
            new Field('version', 'pp.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Planning parameters';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('Item', 'code'),
            new ExportColumn('Name', 'itemName'),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Production lead time (days)', 'productionLeadTimeDays'),
            new ExportColumn('Purchase lead time (days)', 'purchaseLeadTimeDays'),
            new ExportColumn('Safety stock', 'safetyStock'),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Lot policy', 'lotPolicy'),
            new ExportColumn('Changed by', 'updatedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false], ['plantCode', false]];
    }
}
