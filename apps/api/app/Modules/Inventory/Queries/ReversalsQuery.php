<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.reversals` — reversal requests and their decisions (doc 04 row 13, D-060, D-101), each with the document it
 * reverses. Read with `inventory.reversal.view`, by those who request corrections or approve them, inside the plants of
 * those scopes.
 */
final class ReversalsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'inventory.reversals';
    }

    public function permission(): string
    {
        return 'inventory.reversal.view';
    }

    public function permissions(): array
    {
        return ['inventory.reversal.view', 'inventory.reversal.request_correction', 'inventory.reversal.approve'];
    }

    public function from(): string
    {
        return "inventory_documents d
                 join inventory_documents o on o.tenant_id = d.tenant_id and o.id = d.reverses_document_id and d.doc_type = 'REVERSAL'
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id
                 left join users ur on ur.id = d.requested_by
                 left join users ud on ud.id = d.decided_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join inventory_documents o on o.tenant_id = d.tenant_id and o.id = d.reverses_document_id and d.doc_type = 'REVERSAL'
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id";
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
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: ['SUBMITTED', 'REJECTED', 'POSTED']),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
            new Field('reason', 'd.note'),
            new Field('originalId', 'o.id', Field::UUID, filterable: true),
            new Field('originalCode', 'o.doc_no', sortable: true),
            new Field('originalType', 'o.doc_type', Field::ENUM, filterable: true, options: DocumentsQuery::TYPES),
            new Field('originalDate', 'o.effective_date::text'),
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
        return 'Reversals';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Reversal', 'code'),
            new ExportColumn('Status', 'status'),
            new ExportColumn('Reverses', 'originalCode'),
            new ExportColumn('Type', 'originalType', labels: DocumentsQuery::TYPE_LABELS),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Reason', 'reason'),
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
