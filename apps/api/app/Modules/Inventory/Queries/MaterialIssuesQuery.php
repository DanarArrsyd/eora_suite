<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `materials.issues` — material issues to work orders (doc 04 row 3, D-110), one row per issue document with its work
 * order and work center. Read with `materials.issue.view` inside the plants of its scopes.
 */
final class MaterialIssuesQuery extends QueryDefinition
{
    public const array STATUSES = ['POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'materials.issues';
    }

    public function permission(): string
    {
        return 'materials.issue.view';
    }

    public function from(): string
    {
        // the Material at Production leg names the work center (one per issue, D-110)
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'MAT_ISSUE'
                 join work_orders w on w.tenant_id = d.tenant_id and w.id = d.source_id
                 join items wi on wi.tenant_id = w.tenant_id and wi.id = w.item_id
                 cross join lateral (select l.work_center_id, count(*) filter (where m.qty_signed > 0) over () as lines
                                       from inventory_movements m join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id
                                      where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0 limit 1) x
                 join work_centers wc on wc.tenant_id = d.tenant_id and wc.id = x.work_center_id
                 left join users up on up.id = d.posted_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'MAT_ISSUE'
                 join work_orders w on w.tenant_id = d.tenant_id and w.id = d.source_id";
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
            new Field('workOrderId', 'd.source_id', Field::UUID, filterable: true),
            new Field('workOrderCode', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('itemCode', 'wi.code'),
            new Field('itemName', 'wi.name'),
            new Field('workCenterId', 'x.work_center_id', Field::UUID),
            new Field('workCenterCode', 'wc.code'),
            new Field('lines', 'x.lines', Field::INTEGER),
            new Field('productionDate', 'd.production_date::text', sortable: true),
            new Field('note', 'd.note'),
            new Field('postedByName', 'up.display_name'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Material issues';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Issue', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Production date', 'productionDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Lines', 'lines', ExportColumn::INTEGER),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
