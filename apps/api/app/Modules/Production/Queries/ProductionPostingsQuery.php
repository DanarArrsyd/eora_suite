<?php

declare(strict_types=1);

namespace App\Modules\Production\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `production.postings` — production postings (doc 04 rows 5 and 8, D-111): one row per posting with its run, work order,
 * output by category, scrap and output lot. Read with `production.posting.view` inside the plants of its scopes.
 */
final class ProductionPostingsQuery extends QueryDefinition
{
    public const array STATUSES = ['POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    private const string SUM = "(select coalesce(sum(m.qty_signed), 0) from inventory_movements m where m.tenant_id = d.tenant_id and m.document_id = d.id
                                   and m.movement_code = '%s' and m.qty_signed > 0)";

    public function resource(): string
    {
        return 'production.postings';
    }

    public function permission(): string
    {
        return 'production.posting.view';
    }

    public function from(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'PRODUCTION_POSTING'
                 join production_runs r on r.tenant_id = d.tenant_id and r.id = d.source_id
                 join work_orders w on w.tenant_id = r.tenant_id and w.id = r.work_order_id
                 join items i on i.tenant_id = w.tenant_id and i.id = w.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 join work_centers wc on wc.tenant_id = r.tenant_id and wc.id = r.work_center_id
                 left join shifts sh on sh.tenant_id = d.tenant_id and sh.id = d.shift_id
                 left join inventory_documents cd on cd.tenant_id = d.tenant_id and cd.id = d.corrects_document_id
                 left join lateral (select l.lot_no from inventory_movements m join lots l on l.tenant_id = m.tenant_id and l.id = m.lot_id
                                     where m.tenant_id = d.tenant_id and m.document_id = d.id and m.movement_code in ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT')
                                     limit 1) ol on true
                 left join users up on up.id = d.posted_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'PRODUCTION_POSTING'
                 join production_runs r on r.tenant_id = d.tenant_id and r.id = d.source_id
                 join work_orders w on w.tenant_id = r.tenant_id and w.id = r.work_order_id";
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
            new Field('plantCode', 'p.code'),
            new Field('runId', 'r.id', Field::UUID, filterable: true),
            new Field('runCode', 'r.run_no', search: 'prefix'),
            new Field('workOrderId', 'w.id', Field::UUID, filterable: true),
            new Field('workOrderCode', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('itemCode', 'i.code'),
            new Field('uomCode', 'u.code'),
            new Field('workCenterCode', 'wc.code'),
            new Field('productionDate', 'd.production_date::text', sortable: true),
            new Field('shiftCode', 'sh.code'),
            new Field('goodQty', 'trim_scale('.sprintf(self::SUM, 'OUTPUT_GOOD').')::text'),
            new Field('ngReworkQty', 'trim_scale('.sprintf(self::SUM, 'OUTPUT_NG_REWORK').')::text'),
            new Field('ngRejectQty', 'trim_scale('.sprintf(self::SUM, 'OUTPUT_NG_REJECT').')::text'),
            // scrap per unit of its items (D-111: each in its scrap item's unit), e.g. "12 KG, 3 PCS"; null when none
            new Field('scrapSummary', "(select string_agg(s.q || ' ' || s.uom, ', ' order by s.uom) from (
                                         select trim_scale(sum(m.qty_signed))::text as q, su.code as uom from inventory_movements m join uoms su on su.id = m.uom_id
                                          where m.tenant_id = d.tenant_id and m.document_id = d.id and m.movement_code = 'OUTPUT_SCRAP' and m.qty_signed > 0
                                          group by su.code) s)"),
            new Field('outputLotNo', 'ol.lot_no'),
            new Field('correctsCode', 'cd.doc_no'),
            new Field('note', 'd.note'),
            new Field('postedByName', 'up.display_name'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Production postings';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Posting', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Production date', 'productionDate', ExportColumn::DATE),
            new ExportColumn('Shift', 'shiftCode'),
            new ExportColumn('Run', 'runCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Good', 'goodQty', ExportColumn::DECIMAL),
            new ExportColumn('NG rework', 'ngReworkQty', ExportColumn::DECIMAL),
            new ExportColumn('NG reject', 'ngRejectQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Scrap', 'scrapSummary'),
            new ExportColumn('Output lot', 'outputLotNo'),
            new ExportColumn('Corrects', 'correctsCode'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
