<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.movements` — the ledger legs of posted documents in the plants inside the caller's `inventory.stock.view`
 * scopes, each with its document, newest first (BR-INV-02: append-only; a reversal leg names the leg it negates).
 * `stockSide=true` keeps the legs at physical locations: what entered or left stock, without the virtual counter-legs.
 */
final class MovementsQuery extends QueryDefinition
{
    /** D-084 codes posted in M3 and M4 so far (doc 04 §Movement codes). */
    public const array CODES = ['RCPT_SUPPLIER', 'INCOMING_RELEASE', 'TRANSFER_LOC', 'LOT_SPLIT_OUT', 'LOT_SPLIT_IN', 'ADJ_PLUS', 'ADJ_MINUS', 'ISSUE_TO_PROD', 'RETURN_FROM_PROD', 'CONSUME',
        'OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT', 'OUTPUT_SCRAP',
        'QC_RELEASE', 'QC_HOLD', 'QC_REWORK', 'QC_REJECT'];

    public const array CODE_LABELS = [
        'RCPT_SUPPLIER' => 'Supplier receipt', 'INCOMING_RELEASE' => 'Incoming release', 'TRANSFER_LOC' => 'Transfer', 'LOT_SPLIT_OUT' => 'Split out',
        'LOT_SPLIT_IN' => 'Split in', 'ADJ_PLUS' => 'Adjustment plus', 'ADJ_MINUS' => 'Adjustment minus', 'ISSUE_TO_PROD' => 'Issue to production',
        'RETURN_FROM_PROD' => 'Return from production', 'CONSUME' => 'Consumed', 'OUTPUT_GOOD' => 'Output good', 'OUTPUT_NG_REWORK' => 'Output NG rework',
        'OUTPUT_NG_REJECT' => 'Output NG reject', 'OUTPUT_SCRAP' => 'Process scrap',
        'QC_RELEASE' => 'QC release', 'QC_HOLD' => 'QC hold', 'QC_REWORK' => 'QC rework', 'QC_REJECT' => 'QC reject',
    ];

    public function resource(): string
    {
        return 'inventory.movements';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function from(): string
    {
        // every leg has its document, plant, location, item and unit (foreign keys); they are LEFT JOINs so the planner
        // always reads the page's legs first, by their id, and never starts from a small table and scans the ledger
        return 'inventory_movements m
                 left join inventory_documents d on d.tenant_id = m.tenant_id and d.id = m.document_id
                 left join plants p on p.tenant_id = m.tenant_id and p.id = m.plant_id
                 left join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id
                 left join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                 left join uoms u on u.tenant_id = m.tenant_id and u.id = m.uom_id
                 left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id
                 left join users up on up.id = d.posted_by';
    }

    public function filterFrom(): string
    {
        // counting and paging read the ledger alone: every filter is a predicate on the leg itself
        return 'inventory_movements m';
    }

    public function idColumn(): string
    {
        return 'm.id';
    }

    public function plantColumn(): string
    {
        return 'm.plant_id';
    }

    public function companyColumn(): string
    {
        return '(select pc.company_id from plants pc where pc.id = m.plant_id)';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'm.id', Field::UUID, filterable: true),
            // posting order: the ledger id is a UUIDv7 (index inventory_movements_ledger_idx)
            new Field('sequence', 'm.id', Field::UUID, sortable: true),
            new Field('code', 'd.doc_no', search: 'prefix',
                searchSql: 'm.document_id = any (array(select x.id from inventory_documents x where x.doc_no {match}))'),
            new Field('documentId', 'm.document_id', Field::UUID, filterable: true),
            new Field('docType', 'd.doc_type', Field::ENUM, filterable: true, options: DocumentsQuery::TYPES,
                filterSql: 'exists (select 1 from inventory_documents x where x.id = m.document_id and x.doc_type {in})'),
            new Field('documentStatus', 'd.status'),
            new Field('legNo', 'm.leg_no', Field::INTEGER, sortable: true),
            new Field('movementCode', 'm.movement_code', Field::ENUM, filterable: true, options: self::CODES),
            new Field('plantId', 'm.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code'),
            new Field('locationId', 'm.location_id', Field::UUID, filterable: true),
            new Field('locationCode', 'l.code'),
            new Field('locationType', 'l.location_type'),
            new Field('stockSide', "(exists (select 1 from locations ls where ls.id = m.location_id and ls.location_type not like 'V\\_%'))", Field::BOOLEAN, filterable: true),
            new Field('itemId', 'm.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', search: 'prefix', searchSql: self::itemMatch('m.item_id', 'code')),
            new Field('itemName', 'i.name'),
            new Field('lotId', 'm.lot_id', Field::UUID, filterable: true),
            new Field('lotNo', 'lt.lot_no'),
            new Field('stockStatus', 'm.stock_status', Field::ENUM, filterable: true, options: StockQuery::STATUSES),
            new Field('qty', 'trim_scale(m.qty_signed)::text'),
            new Field('uomCode', 'u.code'),
            new Field('reversesMovementId', 'm.reverses_movement_id', Field::UUID),
            new Field('effectiveDate', 'd.effective_date::text'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT),
            new Field('postedByName', 'up.display_name'),
            new Field('version', '1', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Movement ledger';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Posted', 'postedAt', ExportColumn::INSTANT),
            new ExportColumn('Document', 'code'),
            new ExportColumn('Type', 'docType', labels: DocumentsQuery::TYPE_LABELS),
            new ExportColumn('Leg', 'legNo', ExportColumn::INTEGER),
            new ExportColumn('Movement', 'movementCode', labels: self::CODE_LABELS),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Location', 'locationCode'),
            new ExportColumn('Location kind', 'locationType', labels: Labels::LOCATION_TYPES),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Item name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Status', 'stockStatus', labels: StockQuery::STATUS_LABELS),
            new ExportColumn('Quantity', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['sequence', true]];
    }
}
