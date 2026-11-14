<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.documents` — posted inventory documents of the plants inside the caller's `inventory.stock.view` scopes,
 * with their reversal: the one that reversed them, or the request waiting for approval (doc 04 row 13, D-101).
 */
final class DocumentsQuery extends QueryDefinition
{
    public const array TYPES = ['RM_RECEIPT', 'INCOMING_RELEASE', 'MAT_TRANSFER', 'LOT_SPLIT', 'MAT_ISSUE', 'MAT_RETURN', 'PRODUCTION_POSTING', 'QC_DISPOSITION', 'FG_RECEIPT', 'STOCK_ADJUSTMENT', 'REVERSAL'];

    public const array TYPE_LABELS = [
        'RM_RECEIPT' => 'Receipt', 'INCOMING_RELEASE' => 'Incoming release', 'MAT_TRANSFER' => 'Transfer', 'LOT_SPLIT' => 'Lot split',
        'MAT_ISSUE' => 'Material issue', 'MAT_RETURN' => 'Material return', 'PRODUCTION_POSTING' => 'Production posting', 'QC_DISPOSITION' => 'QC disposition', 'FG_RECEIPT' => 'FG receipt', 'STOCK_ADJUSTMENT' => 'Adjustment', 'REVERSAL' => 'Reversal',
    ];

    public function resource(): string
    {
        return 'inventory.documents';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function from(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.status in ('POSTED', 'REVERSED')
                 left join users up on up.id = d.posted_by
                 left join inventory_documents r on r.tenant_id = d.tenant_id and r.reverses_document_id = d.id and r.status in ('SUBMITTED', 'POSTED')";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.status in ('POSTED', 'REVERSED')";
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
            new Field('code', 'd.doc_no', sortable: true, filterable: true, search: 'prefix'),
            new Field('docType', 'd.doc_type', Field::ENUM, sortable: true, filterable: true, options: self::TYPES),
            new Field('status', 'd.status', Field::ENUM, filterable: true, options: ['POSTED', 'REVERSED']),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('postedByName', 'up.display_name'),
            new Field('note', 'd.note'),
            new Field('reversesDocumentId', 'd.reverses_document_id', Field::UUID),
            new Field('reversalId', 'r.id', Field::UUID),
            new Field('reversalStatus', 'r.status'),
            new Field('reversalCode', 'r.doc_no'),
            // what a reversal request may name: posted, not a reversal, nothing pending or posted against it
            new Field('reversible', "(d.status = 'POSTED' and d.doc_type <> 'REVERSAL' and not exists (select 1 from inventory_documents x where x.tenant_id = d.tenant_id and x.reverses_document_id = d.id and x.status <> 'REJECTED'))", Field::BOOLEAN, filterable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Inventory documents';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Document', 'code'),
            new ExportColumn('Type', 'docType', labels: self::TYPE_LABELS),
            new ExportColumn('Status', 'status'),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Posted by', 'postedByName'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Reversal', 'reversalCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
