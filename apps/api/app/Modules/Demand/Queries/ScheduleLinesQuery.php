<?php

declare(strict_types=1);

namespace App\Modules\Demand\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `demand.schedule_lines` — Delivery Schedule Lines with their order, customer and item (doc 33 §1.1–1.2, D-034), e.g.
 * the firm deliveries a Work Order may be pegged to (D-109). Read with `demand.sales_order.view` inside its plants.
 */
final class ScheduleLinesQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'FIRM', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CANCELLED', 'SUPERSEDED'];

    public function resource(): string
    {
        return 'demand.schedule_lines';
    }

    public function permission(): string
    {
        return 'demand.sales_order.view';
    }

    public function from(): string
    {
        return 'delivery_schedule_lines s
                 join sales_order_lines l on l.tenant_id = s.tenant_id and l.id = s.so_line_id
                 join sales_order_headers h on h.tenant_id = l.tenant_id and h.id = l.sales_order_id
                 join customers c on c.tenant_id = h.tenant_id and c.id = h.customer_id
                 join items i on i.tenant_id = l.tenant_id and i.id = l.item_id
                 join uoms u on u.tenant_id = l.tenant_id and u.id = l.uom_id
                 join plants p on p.tenant_id = s.tenant_id and p.id = s.plant_id';
    }

    public function filterFrom(): string
    {
        return 'delivery_schedule_lines s
                 join sales_order_lines l on l.tenant_id = s.tenant_id and l.id = s.so_line_id
                 join sales_order_headers h on h.tenant_id = l.tenant_id and h.id = l.sales_order_id
                 join plants p on p.tenant_id = s.tenant_id and p.id = s.plant_id';
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
        return [
            new Field('id', 's.id', Field::UUID, filterable: true),
            new Field('code', 'h.so_no', sortable: true, search: 'prefix'),
            new Field('status', 's.status', Field::ENUM, filterable: true, options: self::STATUSES),
            new Field('plantId', 's.plant_id', Field::UUID, filterable: true),
            new Field('itemId', 'l.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code'),
            new Field('customerCode', 'c.code'),
            new Field('lineNo', 'l.line_no', Field::INTEGER),
            new Field('customerPoNo', 'l.customer_po_no'),
            new Field('requirementDate', 's.requirement_date::text', sortable: true),
            new Field('qty', 'trim_scale(s.required_qty)::text'),
            new Field('uomCode', 'u.code'),
            new Field('version', 's.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Delivery schedule lines';
    }

    public function defaultSort(): array
    {
        return [['requirementDate', false]];
    }
}
