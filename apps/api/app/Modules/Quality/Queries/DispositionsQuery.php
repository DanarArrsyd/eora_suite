<?php

declare(strict_types=1);

namespace App\Modules\Quality\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `quality.dispositions` — quality dispositions and releases out of policy (doc 04 row 9, D-113): one row per document
 * with its lot and the quantity of each outcome; a release out of policy shows who asked and who decided. Read with
 * `quality.inspection.view` or `quality.disposition_release.approve` inside the plants of those scopes.
 */
final class DispositionsQuery extends QueryDefinition
{
    public const array STATUSES = ['SUBMITTED', 'REJECTED', 'POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['SUBMITTED' => 'Waiting for approval', 'REJECTED' => 'Rejected', 'POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    /** Quantity of an outcome: posted legs, or the asked leg of a request. */
    private const string QTY = "trim_scale(coalesce((select sum(m.qty_signed) from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id
                                   and m.movement_code = '%1\$s' and m.qty_signed > 0),
                                 (select sum((e ->> 'qty')::numeric) from jsonb_array_elements(d.request -> 'legs') e
                                   where e ->> 'movementCode' = '%1\$s' and (e ->> 'qty')::numeric > 0), 0))::text";

    /** Each outcome's reason and note, as recorded on its leg (D-113), e.g. "Hold: Dimension out of tolerance — Hole 0.2 mm off". */
    private const string REASONS = "(select string_agg(
                                       case m.movement_code when 'QC_RELEASE' then 'Release' when 'QC_HOLD' then 'Hold' when 'QC_REWORK' then 'Rework' else 'Reject' end
                                       || coalesce(': ' || case m.reason_code when 'DIMENSION' then 'Dimension out of tolerance' when 'VISUAL' then 'Visual (scratch, dent)'
                                            when 'BURR' then 'Burr' when 'CRACK' then 'Crack' when 'MATERIAL' then 'Material' when 'OTHER' then 'Other' end, '')
                                       || coalesce(' — ' || m.reason_note, ''), '; ' order by m.leg_no)
                                     from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0
                                      and (m.reason_code is not null or m.reason_note is not null))";

    public function resource(): string
    {
        return 'quality.dispositions';
    }

    public function permission(): string
    {
        return 'quality.inspection.view';
    }

    public function permissions(): array
    {
        return ['quality.inspection.view', 'quality.disposition_release.approve'];
    }

    public function from(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'QC_DISPOSITION'
                 cross join lateral (
                   select coalesce((select m.item_id from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id limit 1),
                                   (d.request -> 'legs' -> 0 ->> 'itemId')::uuid) as item_id,
                          coalesce((select m.lot_id from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id limit 1),
                                   (d.request -> 'legs' -> 0 ->> 'lotId')::uuid) as lot_id,
                          coalesce((select m.stock_status from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed < 0 limit 1),
                                   d.request -> 'legs' -> 0 ->> 'stockStatus') as from_status) x
                 join items i on i.tenant_id = d.tenant_id and i.id = x.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 left join lots lt on lt.tenant_id = d.tenant_id and lt.id = x.lot_id
                 left join users ur on ur.id = d.requested_by
                 left join users ud on ud.id = coalesce(d.decided_by, d.posted_by)
                 left join lateral (select r.id, r.note, r.requested_by from inventory_documents r
                                     where r.tenant_id = d.tenant_id and r.reverses_document_id = d.id and r.status = 'SUBMITTED' limit 1) rv on true
                 left join users urv on urv.id = rv.requested_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'QC_DISPOSITION'";
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
            new Field('code', "coalesce(d.doc_no, 'Release request')", sortable: true),
            new Field('docNo', 'd.doc_no', search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('outOfPolicy', '(d.requested_by is not null)', Field::BOOLEAN, filterable: true),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('itemId', 'i.id', Field::UUID),
            new Field('itemCode', 'i.code'),
            new Field('itemName', 'i.name'),
            new Field('lotNo', 'lt.lot_no'),
            new Field('fromStatus', 'x.from_status'),
            new Field('releasedQty', sprintf(self::QTY, 'QC_RELEASE')),
            new Field('holdQty', sprintf(self::QTY, 'QC_HOLD')),
            new Field('reworkQty', sprintf(self::QTY, 'QC_REWORK')),
            new Field('rejectQty', sprintf(self::QTY, 'QC_REJECT')),
            new Field('uomCode', 'u.code'),
            new Field('note', 'd.note'),
            new Field('reasons', self::REASONS),
            new Field('requestedById', 'd.requested_by', Field::UUID),
            new Field('requestedByName', 'ur.display_name'),
            new Field('decidedByName', 'ud.display_name'),
            new Field('rejectReason', 'd.reject_reason'),
            new Field('at', 'coalesce(d.posted_at, d.submitted_at)', Field::INSTANT, sortable: true),
            // a reversal of this disposition waiting for a quality supervisor (D-113)
            new Field('reversalId', 'rv.id', Field::UUID),
            new Field('reversalReason', 'rv.note'),
            new Field('reversalRequestedById', 'rv.requested_by', Field::UUID),
            new Field('reversalRequestedByName', 'urv.display_name'),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Quality dispositions';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Disposition', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('From', 'fromStatus'),
            new ExportColumn('Released', 'releasedQty', ExportColumn::DECIMAL),
            new ExportColumn('Hold', 'holdQty', ExportColumn::DECIMAL),
            new ExportColumn('Rework', 'reworkQty', ExportColumn::DECIMAL),
            new ExportColumn('Reject', 'rejectQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Reasons', 'reasons'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Asked by', 'requestedByName'),
            new ExportColumn('Decided by', 'decidedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['at', true]];
    }
}
