<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `materials.returns` — returns of material at production (doc 04 row 6, D-110): asked by production, received by
 * materials with the measured quantity (both kept), or refused. Read with `materials.return.view` inside the plants of its
 * scopes.
 */
final class MaterialReturnsQuery extends QueryDefinition
{
    public const array STATUSES = ['SUBMITTED', 'REJECTED', 'POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['SUBMITTED' => 'Waiting for receipt', 'REJECTED' => 'Refused', 'POSTED' => 'Received', 'REVERSED' => 'Reversed'];

    private const string ASKED = "(d.request -> 'asked')";

    public function resource(): string
    {
        return 'materials.returns';
    }

    public function permission(): string
    {
        return 'materials.return.view';
    }

    public function from(): string
    {
        $a = self::ASKED;

        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'MAT_RETURN' and d.requested_by is not null
                 join work_orders w on w.tenant_id = d.tenant_id and w.id = d.source_id
                 join locations lp on lp.tenant_id = d.tenant_id and lp.id = ({$a} ->> 'locationId')::uuid
                 join work_centers wc on wc.tenant_id = lp.tenant_id and wc.id = lp.work_center_id
                 join items i on i.tenant_id = d.tenant_id and i.id = ({$a} ->> 'itemId')::uuid
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 left join lots lt on lt.tenant_id = d.tenant_id and lt.id = ({$a} ->> 'lotId')::uuid
                 left join lateral (select m.qty_signed, m.location_id from inventory_movements m
                                     where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0 limit 1) r on true
                 left join locations lr on lr.tenant_id = d.tenant_id and lr.id = r.location_id
                 left join users ur on ur.id = d.requested_by
                 left join users ud on ud.id = d.decided_by";
    }

    public function filterFrom(): string
    {
        $a = self::ASKED;

        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'MAT_RETURN' and d.requested_by is not null
                 join work_orders w on w.tenant_id = d.tenant_id and w.id = d.source_id
                 join items i on i.tenant_id = d.tenant_id and i.id = ({$a} ->> 'itemId')::uuid";
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
        $a = self::ASKED;
        $asked = "({$a} ->> 'qty')::numeric";

        return [
            new Field('id', 'd.id', Field::UUID, filterable: true),
            // a return has its number once it is received
            new Field('code', "coalesce(d.doc_no, 'RTN request')", sortable: true),
            new Field('docNo', 'd.doc_no', search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('workOrderId', 'd.source_id', Field::UUID, filterable: true),
            new Field('workOrderCode', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('workCenterId', 'wc.id', Field::UUID),
            new Field('workCenterCode', 'wc.code'),
            new Field('fromLocationCode', 'lp.code'),
            new Field('itemId', 'i.id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch("({$a} ->> 'itemId')::uuid", 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch("({$a} ->> 'itemId')::uuid", 'name')),
            new Field('lotId', 'lt.id', Field::UUID),
            new Field('lotNo', 'lt.lot_no'),
            new Field('askedQty', "trim_scale({$asked})::text"),
            new Field('receivedQty', 'trim_scale(r.qty_signed)::text'),
            // received less asked (negative: less came back than was asked)
            new Field('differenceQty', "trim_scale(r.qty_signed - {$asked})::text"),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('toLocationCode', 'lr.code'),
            new Field('note', 'd.note'),
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
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
        return 'Material returns';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Return', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Material', 'itemCode'),
            new ExportColumn('Material name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Asked', 'askedQty', ExportColumn::DECIMAL),
            new ExportColumn('Received', 'receivedQty', ExportColumn::DECIMAL),
            new ExportColumn('Difference', 'differenceQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Received into', 'toLocationCode'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Asked by', 'requestedByName'),
            new ExportColumn('Decided by', 'decidedByName'),
            new ExportColumn('Refusal reason', 'rejectReason'),
        ];
    }

    public function defaultSort(): array
    {
        return [['submittedAt', true]];
    }
}
