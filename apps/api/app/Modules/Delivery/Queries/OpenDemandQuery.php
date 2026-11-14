<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `delivery.schedule_lines` — firm schedule lines still to deliver (doc 33 §1.7, D-115, BR-DEM-10): what each requires
 * and has allocated, staged (picked) and delivered, and what is still to allocate. Read with `delivery.shipment.view`
 * inside the plants of its scopes.
 */
final class OpenDemandQuery extends QueryDefinition
{
    private const string ALLOCATED = "coalesce((select sum(a.qty) from stock_allocations a where a.tenant_id = s.tenant_id and a.demand_type = 'SCHEDULE_LINE' and a.demand_id = s.id and a.status = 'ACTIVE'), 0)";

    private const string AT = "coalesce((select sum(m.qty_signed) from inventory_movements m join locations ml on ml.tenant_id = m.tenant_id and ml.id = m.location_id
                                where m.tenant_id = s.tenant_id and m.schedule_line_id = s.id and ml.location_type = '%s'), 0)";

    public function resource(): string
    {
        return 'delivery.schedule_lines';
    }

    public function permission(): string
    {
        return 'delivery.shipment.view';
    }

    public function from(): string
    {
        return "delivery_schedule_lines s
                 join sales_order_lines l on l.tenant_id = s.tenant_id and l.id = s.so_line_id
                 join sales_order_headers h on h.tenant_id = l.tenant_id and h.id = l.sales_order_id
                 join customers c on c.tenant_id = h.tenant_id and c.id = h.customer_id
                 join items i on i.tenant_id = l.tenant_id and i.id = l.item_id
                 join uoms u on u.tenant_id = l.tenant_id and u.id = l.uom_id
                 join plants p on p.tenant_id = s.tenant_id and p.id = s.plant_id and s.status in ('FIRM', 'PARTIALLY_FULFILLED')";
    }

    public function filterFrom(): string
    {
        return "delivery_schedule_lines s
                 join sales_order_lines l on l.tenant_id = s.tenant_id and l.id = s.so_line_id
                 join sales_order_headers h on h.tenant_id = l.tenant_id and h.id = l.sales_order_id
                 join plants p on p.tenant_id = s.tenant_id and p.id = s.plant_id and s.status in ('FIRM', 'PARTIALLY_FULFILLED')";
    }

    public function idColumn(): string
    {
        return 's.id';
    }

    public function plantColumn(): string
    {
        return 's.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        $staged = sprintf(self::AT, 'STAGING');
        $delivered = sprintf(self::AT, 'V_CUSTOMER');

        return [
            new Field('id', 's.id', Field::UUID, filterable: true),
            new Field('code', 'h.so_no', sortable: true, search: 'prefix'),
            new Field('status', 's.status', Field::ENUM, filterable: true, options: ['FIRM', 'PARTIALLY_FULFILLED']),
            new Field('plantId', 's.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('customerId', 'h.customer_id', Field::UUID, filterable: true),
            new Field('customerCode', 'c.code'),
            new Field('customerName', 'c.name'),
            new Field('itemId', 'l.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code'),
            new Field('itemName', 'i.name'),
            new Field('lineNo', 'l.line_no', Field::INTEGER),
            new Field('scheduleNo', 's.schedule_no', Field::INTEGER),
            new Field('customerPoNo', 'l.customer_po_no'),
            new Field('requirementDate', 's.requirement_date::text', sortable: true),
            new Field('requiredQty', 'trim_scale(s.required_qty)::text'),
            new Field('allocatedQty', 'trim_scale('.self::ALLOCATED.')::text'),
            new Field('stagedQty', "trim_scale({$staged})::text"),
            new Field('deliveredQty', "trim_scale({$delivered})::text"),
            new Field('toAllocateQty', 'trim_scale(greatest(s.required_qty - '.self::ALLOCATED." - {$staged} - {$delivered}, 0))::text"),
            new Field('uomCode', 'u.code'),
            new Field('version', 's.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Open delivery demand';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Sales order', 'code'),
            new ExportColumn('Due', 'requirementDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Required', 'requiredQty', ExportColumn::DECIMAL),
            new ExportColumn('Allocated', 'allocatedQty', ExportColumn::DECIMAL),
            new ExportColumn('Staged', 'stagedQty', ExportColumn::DECIMAL),
            new ExportColumn('Delivered', 'deliveredQty', ExportColumn::DECIMAL),
            new ExportColumn('To allocate', 'toAllocateQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['requirementDate', false]];
    }
}
