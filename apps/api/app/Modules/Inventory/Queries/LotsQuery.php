<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.lots` — the lots of lot-controlled items, tenant-wide (a lot is not bound to a plant), for pickers that
 * name a lot without a stock line: e.g. found stock added where the lot has no balance (D-100). Read with
 * `inventory.stock.view`.
 */
final class LotsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'inventory.lots';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function from(): string
    {
        return 'lots lt
                 left join customers oc on oc.tenant_id=lt.tenant_id and oc.id=lt.owner_customer_id
                 left join companies co on co.tenant_id=lt.tenant_id and co.id=lt.owner_company_id
                 join items i on i.tenant_id = lt.tenant_id and i.id = lt.item_id
                 join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id';
    }

    public function idColumn(): string
    {
        return 'lt.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'lt.id', Field::UUID, filterable: true),
            new Field('code', 'lt.lot_no', sortable: true, search: 'prefix'),
            new Field('itemId', 'lt.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true),
            new Field('itemName', 'i.name'),
            new Field('supplierLotNo', 'lt.supplier_lot_no'),
            new Field('ownerCustomerId', 'lt.owner_customer_id', Field::UUID, filterable: true),
            new Field('ownerCompanyId', 'lt.owner_company_id', Field::UUID),
            new Field('ownerCode', 'coalesce(oc.code,co.code)'),
            new Field('ownership', "case when lt.owner_customer_id is null then 'OWN' else 'CUSTOMER' end", Field::ENUM, filterable: true, options: ['OWN', 'CUSTOMER']),
            new Field('origin', 'lt.origin', Field::ENUM, filterable: true, options: ['RECEIPT', 'OUTPUT', 'SPLIT']),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('version', 'lt.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
