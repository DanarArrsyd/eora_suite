<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.movements` — the ledger legs of posted documents in the plants inside the caller's `inventory.stock.view`
 * scopes, each with its document (BR-INV-02: append-only; a reversal leg names the leg it negates).
 */
final class MovementsQuery extends QueryDefinition
{
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
        return 'inventory_movements m
                 join inventory_documents d on d.tenant_id = m.tenant_id and d.id = m.document_id
                 join plants p on p.tenant_id = m.tenant_id and p.id = m.plant_id
                 join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id
                 join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                 join uoms u on u.tenant_id = m.tenant_id and u.id = m.uom_id
                 left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id';
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
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'm.id', Field::UUID, filterable: true),
            new Field('code', 'd.doc_no', sortable: true, search: 'prefix'),
            new Field('documentId', 'm.document_id', Field::UUID, filterable: true),
            new Field('docType', 'd.doc_type', Field::ENUM, filterable: true, options: DocumentsQuery::TYPES),
            new Field('legNo', 'm.leg_no', Field::INTEGER, sortable: true),
            new Field('movementCode', 'm.movement_code'),
            new Field('plantId', 'm.plant_id', Field::UUID, filterable: true),
            new Field('locationId', 'm.location_id', Field::UUID, filterable: true),
            new Field('locationCode', 'l.code'),
            new Field('locationType', 'l.location_type'),
            new Field('itemId', 'm.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code'),
            new Field('lotId', 'm.lot_id', Field::UUID, filterable: true),
            new Field('lotNo', 'lt.lot_no'),
            new Field('stockStatus', 'm.stock_status', Field::ENUM, options: StockQuery::STATUSES),
            new Field('qty', 'trim_scale(m.qty_signed)::text'),
            new Field('uomCode', 'u.code'),
            new Field('reversesMovementId', 'm.reverses_movement_id', Field::UUID),
            new Field('effectiveDate', 'd.effective_date::text', sortable: true),
            new Field('version', '1', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['legNo', false]];
    }
}
