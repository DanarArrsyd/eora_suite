<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Modules\Planning\Http\WorkOrderController;
use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.work_orders` — manual Work Orders (doc 33 §1.2, D-109), one row per WO with its item, the pegged delivery,
 * the release snapshot (BOM and routing versions) and who asked for and released it. Read with `planning.wo.view`, or by
 * an approver with `planning.wo.approve`, inside the plants of those scopes.
 */
final class WorkOrdersQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'SUBMITTED', 'RELEASED', 'IN_PROGRESS', 'ON_HOLD', 'PRODUCTION_COMPLETE', 'CLOSED', 'CANCELLED'];

    public const array STATUS_LABELS = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Waiting for release', 'RELEASED' => 'Released', 'IN_PROGRESS' => 'In progress',
        'ON_HOLD' => 'On hold', 'PRODUCTION_COMPLETE' => 'Production complete', 'CLOSED' => 'Closed', 'CANCELLED' => 'Cancelled'];

    public function resource(): string
    {
        return 'planning.work_orders';
    }

    public function permission(): string
    {
        return 'planning.wo.view';
    }

    public function permissions(): array
    {
        return ['planning.wo.view', 'planning.wo.approve'];
    }

    public function from(): string
    {
        return 'work_orders w
                 join plants p on p.tenant_id = w.tenant_id and p.id = w.plant_id
                 join items i on i.tenant_id = w.tenant_id and i.id = w.item_id
                 join uoms u on u.tenant_id = w.tenant_id and u.id = w.uom_id
                 left join delivery_schedule_lines s on s.tenant_id = w.tenant_id and s.id = w.schedule_line_id
                 left join sales_order_lines sl on sl.tenant_id = s.tenant_id and sl.id = s.so_line_id
                 left join sales_order_headers sh on sh.tenant_id = sl.tenant_id and sh.id = sl.sales_order_id
                 left join customers c on c.tenant_id = sh.tenant_id and c.id = sh.customer_id
                 left join bom_versions bv on bv.tenant_id = w.tenant_id and bv.id = w.bom_version_id
                 left join routing_versions rv on rv.tenant_id = w.tenant_id and rv.id = w.routing_version_id
                 left join routings r on r.tenant_id = rv.tenant_id and r.id = rv.routing_id
                 left join users ucr on ucr.id = w.created_by
                 left join users usb on usb.id = w.submitted_by
                 left join users url on url.id = w.released_by
                 left join users ucn on ucn.id = w.cancelled_by';
    }

    public function filterFrom(): string
    {
        return 'work_orders w
                 join plants p on p.tenant_id = w.tenant_id and p.id = w.plant_id
                 join items i on i.tenant_id = w.tenant_id and i.id = w.item_id';
    }

    public function idColumn(): string
    {
        return 'w.id';
    }

    public function plantColumn(): string
    {
        return 'w.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'w.id', Field::UUID, filterable: true),
            new Field('code', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('status', 'w.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'w.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('itemId', 'w.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('w.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('w.item_id', 'name')),
            new Field('qty', 'trim_scale(w.qty)::text'),
            new Field('uomCode', 'u.code'),
            new Field('dueDate', 'w.due_date::text', sortable: true),
            new Field('reasonCode', 'w.reason_code', Field::ENUM, filterable: true, options: WorkOrderController::REASONS),
            new Field('note', 'w.note'),
            new Field('scheduleLineId', 'w.schedule_line_id', Field::UUID, filterable: true),
            new Field('salesOrderId', 'sh.id', Field::UUID),
            new Field('salesOrderCode', 'sh.so_no'),
            new Field('customerCode', 'c.code'),
            new Field('scheduleDate', 's.requirement_date::text'),
            new Field('scheduleQty', 'trim_scale(s.required_qty)::text'),
            new Field('bomVersionNo', 'bv.version_no', Field::INTEGER),
            new Field('routingCode', 'r.routing_code'),
            new Field('routingVersionNo', 'rv.version_no', Field::INTEGER),
            new Field('standardYield', 'trim_scale(rv.standard_yield)::text'),
            new Field('createdAt', 'w.created_at', Field::INSTANT, sortable: true),
            new Field('createdByName', 'ucr.display_name'),
            new Field('submittedById', 'w.submitted_by', Field::UUID),
            new Field('submittedByName', 'usb.display_name'),
            new Field('submittedAt', 'w.submitted_at', Field::INSTANT),
            new Field('releasedByName', 'url.display_name'),
            new Field('releasedAt', 'w.released_at', Field::INSTANT, sortable: true),
            new Field('returnReason', 'w.return_reason'),
            new Field('cancelledByName', 'ucn.display_name'),
            new Field('cancelReason', 'w.cancel_reason'),
            new Field('version', 'w.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Work orders';
    }

    /** D-092: the list's columns, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Work order', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Item name', 'itemName'),
            new ExportColumn('Quantity', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Due', 'dueDate', ExportColumn::DATE),
            new ExportColumn('Reason', 'reasonCode'),
            new ExportColumn('Sales order', 'salesOrderCode'),
            new ExportColumn('Customer', 'customerCode'),
            new ExportColumn('BOM version', 'bomVersionNo', ExportColumn::INTEGER),
            new ExportColumn('Routing', 'routingCode'),
            new ExportColumn('Routing version', 'routingVersionNo', ExportColumn::INTEGER),
            new ExportColumn('Released by', 'releasedByName'),
            new ExportColumn('Released', 'releasedAt', ExportColumn::INSTANT),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
