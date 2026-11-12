<?php

declare(strict_types=1);

namespace App\Modules\Demand\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `demand.sales_orders` — Sales Orders (doc 33 §1.1, D-107, D-034), one row per order with its active line count, the
 * customer POs it carries and its next firm delivery date. Read with `demand.sales_order.view` inside the plants of
 * that scope; the order's lines and schedule lines come with `GET /demand/sales-orders/{id}`.
 */
final class SalesOrdersQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'CONFIRMED', 'CANCELLED'];

    public const array STATUS_LABELS = ['DRAFT' => 'Draft', 'CONFIRMED' => 'Confirmed', 'CANCELLED' => 'Cancelled'];

    public function resource(): string
    {
        return 'demand.sales_orders';
    }

    public function permission(): string
    {
        return 'demand.sales_order.view';
    }

    public function from(): string
    {
        return "sales_order_headers h
                 join plants p on p.tenant_id = h.tenant_id and p.id = h.plant_id
                 join customers c on c.tenant_id = h.tenant_id and c.id = h.customer_id
                 left join lateral (
                   select count(*) filter (where l.status = 'ACTIVE') as line_count,
                          string_agg(distinct l.customer_po_no, ', ' order by l.customer_po_no) filter (where l.status = 'ACTIVE') as po_nos,
                          (select min(s.requirement_date) from delivery_schedule_lines s join sales_order_lines sl on sl.id = s.so_line_id
                            where sl.sales_order_id = h.id and s.status in ('FIRM', 'PARTIALLY_FULFILLED')) as next_due
                     from sales_order_lines l where l.sales_order_id = h.id
                 ) agg on true
                 left join users ucr on ucr.id = h.created_by
                 left join users ucf on ucf.id = h.confirmed_by
                 left join users ucn on ucn.id = h.cancelled_by";
    }

    public function filterFrom(): string
    {
        return 'sales_order_headers h
                 join plants p on p.tenant_id = h.tenant_id and p.id = h.plant_id
                 join customers c on c.tenant_id = h.tenant_id and c.id = h.customer_id';
    }

    public function idColumn(): string
    {
        return 'h.id';
    }

    public function plantColumn(): string
    {
        return 'h.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'h.id', Field::UUID, filterable: true),
            new Field('code', 'h.so_no', sortable: true, search: 'prefix'),
            new Field('status', 'h.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'h.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('customerId', 'h.customer_id', Field::UUID, filterable: true),
            new Field('customerCode', 'c.code', sortable: true, search: 'prefix', searchSql: 'h.customer_id = any (array(select x.id from customers x where x.code {match}))'),
            new Field('customerName', 'c.name', search: 'contains', searchSql: 'h.customer_id = any (array(select x.id from customers x where x.name {match}))'),
            new Field('customerPoNos', 'agg.po_nos', search: 'prefix',
                searchSql: "exists (select 1 from sales_order_lines x where x.sales_order_id = h.id and x.status = 'ACTIVE' and upper(x.customer_po_no) {match})"),
            new Field('lineCount', 'agg.line_count', Field::INTEGER),
            new Field('nextDueDate', 'agg.next_due::text'),
            new Field('note', 'h.note'),
            new Field('createdAt', 'h.created_at', Field::INSTANT, sortable: true),
            new Field('createdByName', 'ucr.display_name'),
            new Field('confirmedAt', 'h.confirmed_at', Field::INSTANT, sortable: true),
            new Field('confirmedByName', 'ucf.display_name'),
            new Field('cancelledAt', 'h.cancelled_at', Field::INSTANT),
            new Field('cancelledByName', 'ucn.display_name'),
            new Field('cancelReason', 'h.cancel_reason'),
            new Field('version', 'h.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Sales orders';
    }

    /** D-092: the list's columns, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Sales order', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('Customer name', 'customerName'),
            new ExportColumn('Customer PO', 'customerPoNos'),
            new ExportColumn('Lines', 'lineCount', ExportColumn::INTEGER),
            new ExportColumn('Next delivery', 'nextDueDate', ExportColumn::DATE),
            new ExportColumn('Created', 'createdAt', ExportColumn::INSTANT),
            new ExportColumn('Created by', 'createdByName'),
            new ExportColumn('Confirmed by', 'confirmedByName'),
            new ExportColumn('Note', 'note'),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
