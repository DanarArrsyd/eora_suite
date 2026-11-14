<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `delivery.shipments` — shipments to customers (doc 04 row 11, D-115, D-116), one row per document with its customer,
 * transport, lines and what was shipped. Read with `delivery.shipment.view` inside the plants of its scopes.
 */
final class ShipmentsQuery extends QueryDefinition
{
    public const array STATUSES = ['POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'delivery.shipments';
    }

    public function permission(): string
    {
        return 'delivery.shipment.view';
    }

    public function from(): string
    {
        // the legs into the customer tell what was shipped, for which order and schedule line
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'SHIPMENT'
                 join customers c on c.tenant_id = d.tenant_id and c.id = d.source_id
                 left join shipment_details t on t.tenant_id = d.tenant_id and t.document_id = d.id
                 cross join lateral (
                   select count(*) as lines, string_agg(distinct h.so_no, ', ') as orders,
                          string_agg(i.code || ' ' || coalesce(lt.lot_no, 'no lot') || ' ' || trim_scale(m.qty_signed)::text || ' ' || u.code
                                     || ' (' || h.so_no || ' ' || ol.line_no || '/' || s.schedule_no || ')', '; ' order by m.leg_no) as shipped
                     from inventory_movements m
                     join delivery_schedule_lines s on s.tenant_id = m.tenant_id and s.id = m.schedule_line_id
                     join sales_order_lines ol on ol.tenant_id = s.tenant_id and ol.id = s.so_line_id
                     join sales_order_headers h on h.tenant_id = ol.tenant_id and h.id = ol.sales_order_id
                     join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                     join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                     left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id
                    where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0) x
                 left join users up on up.id = d.posted_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'SHIPMENT'";
    }

    public function idColumn(): string
    {
        return 'd.id';
    }

    public function plantColumn(): string
    {
        return 'd.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'd.id', Field::UUID, filterable: true),
            new Field('code', 'd.doc_no', sortable: true, search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('customerId', 'd.source_id', Field::UUID, filterable: true),
            new Field('customerCode', 'c.code'),
            new Field('customerName', 'c.name'),
            new Field('vehicleNo', 't.vehicle_no'),
            new Field('driverName', 't.driver_name'),
            new Field('lines', 'x.lines', Field::INTEGER),
            new Field('orders', 'x.orders'),
            new Field('shipped', 'x.shipped'),
            new Field('note', 'd.note'),
            new Field('postedByName', 'up.display_name'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Shipments';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Shipment', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('Orders', 'orders'),
            new ExportColumn('Vehicle', 'vehicleNo'),
            new ExportColumn('Driver', 'driverName'),
            new ExportColumn('Lines', 'lines', ExportColumn::INTEGER),
            new ExportColumn('Shipped', 'shipped'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
