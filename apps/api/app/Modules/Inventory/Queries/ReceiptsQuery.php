<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.receipts` — raw-material receipts (RM_RECEIPT documents) of the plants inside the caller's
 * `materials.receipt.view` scopes, one row per receipt with its inbound leg (one item, lot and location per receipt).
 */
final class ReceiptsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'inventory.receipts';
    }

    public function permission(): string
    {
        return 'materials.receipt.view';
    }

    public function from(): string
    {
        return "inventory_documents d
                 join inventory_movements m on m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0 and d.doc_type = 'RM_RECEIPT'
                 join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                 join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id
                 join uoms u on u.tenant_id = m.tenant_id and u.id = m.uom_id
                 left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id
                 left join suppliers s on s.tenant_id = d.tenant_id and s.id = d.source_id and d.source_type = 'supplier'
                 left join users up on up.id = d.posted_by";
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
            new Field('docNo', 'd.doc_no', sortable: true, search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: ['POSTED', 'REVERSED']),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
            new Field('productionDate', 'd.production_date::text'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('postedByName', 'up.display_name'),
            new Field('supplierId', 'd.source_id', Field::UUID, filterable: true),
            new Field('supplierCode', 's.code', sortable: true),
            new Field('supplierName', 's.name'),
            new Field('itemId', 'm.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('m.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('m.item_id', 'name')),
            new Field('lotId', 'm.lot_id', Field::UUID, filterable: true),
            new Field('lotNo', 'lt.lot_no'),
            new Field('supplierLotNo', 'lt.supplier_lot_no'),
            new Field('locationId', 'm.location_id', Field::UUID, filterable: true),
            new Field('locationCode', 'l.code'),
            new Field('stockStatus', 'm.stock_status', Field::ENUM, filterable: true, options: StockQuery::STATUSES),
            new Field('qty', 'trim_scale(m.qty_signed)::text'),
            new Field('uomCode', 'u.code'),
            new Field('note', 'd.note'),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'RM receipts';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Receipt', 'docNo'),
            new ExportColumn('Date', 'effectiveDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Supplier', 'supplierCode'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Item name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Supplier lot', 'supplierLotNo'),
            new ExportColumn('Location', 'locationCode'),
            new ExportColumn('Status', 'stockStatus', labels: StockQuery::STATUS_LABELS),
            new ExportColumn('Quantity', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
