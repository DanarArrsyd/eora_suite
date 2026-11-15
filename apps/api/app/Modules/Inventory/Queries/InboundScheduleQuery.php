<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `materials.inbound_schedule` — the supplier inbound schedule (doc 34 §1.1, D-087, D-121): one row per line with what
 * the posted receipts naming it received (a reversed receipt no longer counts) and the status that follows from it.
 * Reference data only. Read with `materials.inbound_schedule.view` inside the plants of that scope.
 */
final class InboundScheduleQuery extends QueryDefinition
{
    public const array STATUSES = ['OPEN', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'];

    public const array STATUS_LABELS = ['OPEN' => 'Open', 'PARTIALLY_RECEIVED' => 'Partially received', 'RECEIVED' => 'Received', 'CANCELLED' => 'Cancelled'];

    private const string STATUS = "case when l.cancelled_at is not null then 'CANCELLED' when rc.received >= l.qty then 'RECEIVED'
                                        when rc.received > 0 then 'PARTIALLY_RECEIVED' else 'OPEN' end";

    public function resource(): string
    {
        return 'materials.inbound_schedule';
    }

    public function permission(): string
    {
        return 'materials.inbound_schedule.view';
    }

    public function from(): string
    {
        return "supplier_inbound_lines l
                 join plants p on p.tenant_id = l.tenant_id and p.id = l.plant_id
                 join suppliers s on s.tenant_id = l.tenant_id and s.id = l.supplier_id
                 join items i on i.tenant_id = l.tenant_id and i.id = l.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 cross join lateral (
                   select coalesce(sum(m.qty_signed), 0) as received, count(distinct d.id) as receipts
                     from inbound_receipts r
                     join inventory_documents d on d.tenant_id = r.tenant_id and d.id = r.document_id and d.status = 'POSTED'
                     join inventory_movements m on m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0
                    where r.tenant_id = l.tenant_id and r.inbound_line_id = l.id) rc
                 left join users uc on uc.id = l.cancelled_by
                 left join users ub on ub.id = l.created_by";
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
            new Field('code', 'l.inbound_no', sortable: true, search: 'prefix'),
            new Field('status', self::STATUS, Field::ENUM, filterable: true, options: self::STATUSES),
            new Field('plantId', 'l.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('supplierId', 'l.supplier_id', Field::UUID, filterable: true),
            new Field('supplierCode', 's.code', sortable: true, search: 'prefix'),
            new Field('supplierName', 's.name'),
            new Field('itemId', 'l.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('qty', 'trim_scale(l.qty)::text'),
            new Field('receivedQty', 'trim_scale(rc.received)::text'),
            new Field('outstandingQty', 'trim_scale(greatest(l.qty - rc.received, 0))::text'),
            new Field('receipts', 'rc.receipts', Field::INTEGER),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('expectedDate', 'l.expected_date::text', sortable: true),
            new Field('note', 'l.note'),
            new Field('cancelReason', 'l.cancel_reason'),
            new Field('cancelledByName', 'uc.display_name'),
            new Field('createdByName', 'ub.display_name'),
            new Field('version', 'l.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Inbound schedule';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('Inbound', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Supplier', 'supplierCode'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Expected', 'expectedDate'),
            new ExportColumn('Scheduled', 'qty'),
            new ExportColumn('Received', 'receivedQty'),
            new ExportColumn('Outstanding', 'outstandingQty'),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Note', 'note'),
        ];
    }

    public function defaultSort(): array
    {
        return [['expectedDate', false], ['code', false]];
    }
}
