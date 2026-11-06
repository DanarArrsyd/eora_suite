<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Modules\Inventory\Http\AdjustmentController;
use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.adjustments` — stock adjustment requests and their decisions (doc 04 row 12, D-060, D-100), one row per
 * request with its stock line (the leg at the physical location). Read with `inventory.adjustment.view`, or by an
 * approver with `inventory.adjustment.approve`, inside the plants of those scopes.
 */
final class AdjustmentsQuery extends QueryDefinition
{
    public const array STATUSES = ['SUBMITTED', 'REJECTED', 'POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['SUBMITTED' => 'Waiting for approval', 'REJECTED' => 'Rejected', 'POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'inventory.adjustments';
    }

    public function permission(): string
    {
        return 'inventory.adjustment.view';
    }

    public function permissions(): array
    {
        return ['inventory.adjustment.view', 'inventory.adjustment.approve'];
    }

    public function from(): string
    {
        // the leg at the physical location: the second leg of ADJ_PLUS (into stock), the first of ADJ_MINUS (out of it)
        return "inventory_documents d
                 cross join lateral (select d.request -> 'legs' -> (case when d.request -> 'legs' -> 0 ->> 'movementCode' = 'ADJ_PLUS' then 1 else 0 end) as leg) x
                 join items i on i.tenant_id = d.tenant_id and i.id = (x.leg ->> 'itemId')::uuid
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 join locations l on l.tenant_id = d.tenant_id and l.id = (x.leg ->> 'locationId')::uuid
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'STOCK_ADJUSTMENT'
                 left join lots lt on lt.tenant_id = d.tenant_id and lt.id = (x.leg ->> 'lotId')::uuid
                 left join users ur on ur.id = d.requested_by
                 left join users ud on ud.id = d.decided_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 cross join lateral (select d.request -> 'legs' -> (case when d.request -> 'legs' -> 0 ->> 'movementCode' = 'ADJ_PLUS' then 1 else 0 end) as leg) x
                 join items i on i.tenant_id = d.tenant_id and i.id = (x.leg ->> 'itemId')::uuid
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'STOCK_ADJUSTMENT'";
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
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
            new Field('reasonCode', 'd.reason_code', Field::ENUM, filterable: true, options: AdjustmentController::REASONS),
            new Field('note', 'd.note'),
            new Field('direction', "case when d.request -> 'legs' -> 0 ->> 'movementCode' = 'ADJ_PLUS' then 'PLUS' else 'MINUS' end"),
            new Field('itemId', 'i.id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch("(x.leg ->> 'itemId')::uuid", 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch("(x.leg ->> 'itemId')::uuid", 'name')),
            new Field('lotId', 'lt.id', Field::UUID),
            new Field('lotNo', 'lt.lot_no'),
            new Field('locationId', 'l.id', Field::UUID),
            new Field('locationCode', 'l.code'),
            new Field('stockStatus', "x.leg ->> 'stockStatus'", Field::ENUM, options: StockQuery::STATUSES),
            new Field('qty', "trim_scale(abs((x.leg ->> 'qty')::numeric))::text"),
            new Field('uomCode', 'u.code'),
            new Field('requestedById', 'd.requested_by', Field::UUID),
            new Field('requestedByName', 'ur.display_name'),
            new Field('submittedAt', 'd.submitted_at', Field::INSTANT, sortable: true),
            new Field('decidedByName', 'ud.display_name'),
            new Field('decidedAt', 'd.decided_at', Field::INSTANT),
            new Field('rejectReason', 'd.reject_reason'),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Stock adjustments';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Adjustment', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Item name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Location', 'locationCode'),
            new ExportColumn('Stock status', 'stockStatus', labels: StockQuery::STATUS_LABELS),
            new ExportColumn('Direction', 'direction'),
            new ExportColumn('Quantity', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Reason', 'reasonCode'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Requested by', 'requestedByName'),
            new ExportColumn('Decided by', 'decidedByName'),
            new ExportColumn('Rejection reason', 'rejectReason'),
        ];
    }

    public function defaultSort(): array
    {
        return [['submittedAt', true]];
    }
}
