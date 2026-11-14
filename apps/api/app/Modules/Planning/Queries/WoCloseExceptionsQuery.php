<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.wo_close_exceptions` — work order close exceptions (doc 33 §1.8, D-117, BR-PLN-03): one row per request
 * with its work order, the material it scraps, the reason, who asked and who decided. Read with
 * `planning.wo_close_exception.view` or `.approve` inside the plants of those scopes.
 */
final class WoCloseExceptionsQuery extends QueryDefinition
{
    public const array STATUSES = ['SUBMITTED', 'REJECTED', 'POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['SUBMITTED' => 'Waiting for approval', 'REJECTED' => 'Rejected', 'POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'planning.wo_close_exceptions';
    }

    public function permission(): string
    {
        return 'planning.wo_close_exception.view';
    }

    public function permissions(): array
    {
        return ['planning.wo_close_exception.view', 'planning.wo_close_exception.approve'];
    }

    public function from(): string
    {
        // the work order and what is scrapped come from the request legs (the posted legs equal them)
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'WO_CLOSE_EXCEPTION'
                 join work_orders w on w.tenant_id = d.tenant_id and w.id = (d.request -> 'legs' -> 0 ->> 'woId')::uuid
                 join items wi on wi.tenant_id = w.tenant_id and wi.id = w.item_id
                 cross join lateral (
                   select count(*) as lines, string_agg(i.code || ' ' || coalesce(lt.lot_no, 'no lot') || ' ' || trim_scale(-(e ->> 'qty')::numeric)::text || ' ' || u.code, '; ') as scrapped
                     from jsonb_array_elements(d.request -> 'legs') e
                     join items i on i.tenant_id = d.tenant_id and i.id = (e ->> 'itemId')::uuid
                     join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                     left join lots lt on lt.tenant_id = d.tenant_id and lt.id = (e ->> 'lotId')::uuid
                    where (e ->> 'qty')::numeric < 0) x
                 left join users ur on ur.id = d.requested_by
                 left join users ud on ud.id = coalesce(d.decided_by, d.posted_by)";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'WO_CLOSE_EXCEPTION'";
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
            new Field('code', "coalesce(d.doc_no, 'Close exception')"),
            new Field('docNo', 'd.doc_no', search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('workOrderId', 'w.id', Field::UUID),
            new Field('workOrderCode', 'w.wo_no'),
            new Field('itemCode', 'wi.code'),
            new Field('lines', 'x.lines', Field::INTEGER),
            new Field('scrapped', 'x.scrapped'),
            new Field('reason', 'd.note'),
            new Field('requestedById', 'd.requested_by', Field::UUID),
            new Field('requestedByName', 'ur.display_name'),
            new Field('decidedByName', 'ud.display_name'),
            new Field('rejectReason', 'd.reject_reason'),
            new Field('at', 'coalesce(d.posted_at, d.decided_at, d.submitted_at)', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Work order close exceptions';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Exception', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Part', 'itemCode'),
            new ExportColumn('Scrapped', 'scrapped'),
            new ExportColumn('Reason', 'reason'),
            new ExportColumn('Asked by', 'requestedByName'),
            new ExportColumn('Decided by', 'decidedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['at', true]];
    }
}
