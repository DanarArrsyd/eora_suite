<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.customer_items` — customer part numbers mapped to our items, with their effective ranges (`master.part.view`). */
final class CustomerItemsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.customer_items';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'customer_items ci
                 join customers cu on cu.tenant_id = ci.tenant_id and cu.id = ci.customer_id
                 join items i on i.tenant_id = ci.tenant_id and i.id = ci.item_id
                 join tenants t on t.id = ci.tenant_id';
    }

    public function idColumn(): string
    {
        return 'ci.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'ci.id', Field::UUID, filterable: true),
            new Field('customerId', 'ci.customer_id', Field::UUID, filterable: true),
            new Field('customerCode', 'cu.code', sortable: true),
            new Field('code', 'ci.customer_part_no', sortable: true, search: 'contains'),
            new Field('customerDrawingRev', 'ci.customer_drawing_rev'),
            new Field('itemId', 'ci.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('ci.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('ci.item_id', 'name')),
            new Field('effectiveFrom', 'ci.effective_from::text', sortable: true),
            new Field('effectiveTo', 'ci.effective_to::text'),
            // in effect today in the tenant's time zone (D-044)
            new Field('current', '(ci.effective_from <= (now() at time zone t.timezone)::date and (ci.effective_to is null or ci.effective_to >= (now() at time zone t.timezone)::date))', Field::BOOLEAN, filterable: true),
            new Field('status', 'ci.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 'ci.updated_at', Field::INSTANT),
            new Field('version', 'ci.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['customerCode', false], ['code', false], ['effectiveFrom', true]];
    }
}
