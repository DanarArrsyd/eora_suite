<?php

declare(strict_types=1);

namespace App\Modules\Production\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `production.runs` — production runs (D-111): a work order at one work center, open until the production leader closes
 * it, with what it made so far. Read with `production.run.view` inside the plants of its scopes.
 */
final class ProductionRunsQuery extends QueryDefinition
{
    public const array STATUSES = ['OPEN', 'CLOSED', 'CANCELLED'];

    public const array STATUS_LABELS = ['OPEN' => 'Open', 'CLOSED' => 'Closed', 'CANCELLED' => 'Cancelled'];

    /** Pieces of the run's posted (not reversed) output by category. */
    private const string OUTPUT = "(select coalesce(sum(m.qty_signed), 0) from inventory_movements m join inventory_documents d on d.tenant_id = m.tenant_id and d.id = m.document_id
                                    where d.tenant_id = r.tenant_id and d.source_id = r.id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                                      and m.movement_code = '%s' and m.qty_signed > 0)";

    public function resource(): string
    {
        return 'production.runs';
    }

    public function permission(): string
    {
        return 'production.run.view';
    }

    public function from(): string
    {
        return 'production_runs r
                 join plants p on p.tenant_id = r.tenant_id and p.id = r.plant_id
                 join work_orders w on w.tenant_id = r.tenant_id and w.id = r.work_order_id
                 join items i on i.tenant_id = w.tenant_id and i.id = w.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 join work_centers wc on wc.tenant_id = r.tenant_id and wc.id = r.work_center_id
                 left join machines mc on mc.tenant_id = r.tenant_id and mc.id = r.machine_id
                 left join users uo on uo.id = r.opened_by
                 left join users ux on ux.id = coalesce(r.closed_by, r.cancelled_by)';
    }

    public function filterFrom(): string
    {
        return 'production_runs r
                 join plants p on p.tenant_id = r.tenant_id and p.id = r.plant_id
                 join work_orders w on w.tenant_id = r.tenant_id and w.id = r.work_order_id';
    }

    public function idColumn(): string
    {
        return 'r.id';
    }

    public function plantColumn(): string
    {
        return 'r.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'r.id', Field::UUID, filterable: true),
            new Field('code', 'r.run_no', sortable: true, search: 'prefix'),
            new Field('status', 'r.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'r.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('workOrderId', 'r.work_order_id', Field::UUID, filterable: true),
            new Field('workOrderCode', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('workOrderStatus', 'w.status'),
            new Field('itemId', 'w.item_id', Field::UUID),
            new Field('itemCode', 'i.code', sortable: true),
            new Field('itemName', 'i.name'),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('inspectionRequired', 'i.inspection_required', Field::BOOLEAN),
            new Field('workCenterId', 'r.work_center_id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true),
            new Field('machineCode', 'mc.code'),
            new Field('goodQty', 'trim_scale('.sprintf(self::OUTPUT, 'OUTPUT_GOOD').')::text'),
            new Field('ngReworkQty', 'trim_scale('.sprintf(self::OUTPUT, 'OUTPUT_NG_REWORK').')::text'),
            new Field('ngRejectQty', 'trim_scale('.sprintf(self::OUTPUT, 'OUTPUT_NG_REJECT').')::text'),
            new Field('postings', "(select count(*) from inventory_documents d where d.tenant_id = r.tenant_id and d.source_id = r.id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED')", Field::INTEGER),
            new Field('note', 'r.note'),
            new Field('openedByName', 'uo.display_name'),
            new Field('openedAt', 'r.opened_at', Field::INSTANT, sortable: true),
            new Field('closedAt', 'coalesce(r.closed_at, r.cancelled_at)', Field::INSTANT),
            new Field('closedByName', 'ux.display_name'),
            new Field('cancelReason', 'r.cancel_reason'),
            new Field('version', 'r.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Production runs';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Run', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Machine', 'machineCode'),
            new ExportColumn('Good', 'goodQty', ExportColumn::DECIMAL),
            new ExportColumn('NG rework', 'ngReworkQty', ExportColumn::DECIMAL),
            new ExportColumn('NG reject', 'ngRejectQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Opened by', 'openedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['openedAt', true]];
    }
}
