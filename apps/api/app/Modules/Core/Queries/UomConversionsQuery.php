<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.uom_conversions` — item unit conversions with their effective ranges (`master.part.view`). */
final class UomConversionsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.uom_conversions';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'uom_conversions c
                 join items i on i.tenant_id = c.tenant_id and i.id = c.item_id
                 join uoms fu on fu.tenant_id = c.tenant_id and fu.id = c.from_uom_id
                 join uoms tu on tu.tenant_id = c.tenant_id and tu.id = c.to_uom_id
                 join tenants t on t.id = c.tenant_id';
    }

    public function idColumn(): string
    {
        return 'c.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'c.id', Field::UUID, filterable: true),
            new Field('itemId', 'c.item_id', Field::UUID, filterable: true),
            new Field('code', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('fromUomCode', 'fu.code'),
            new Field('toUomCode', 'tu.code'),
            new Field('factor', 'c.factor::text'),
            new Field('effectiveFrom', 'c.effective_from::text', sortable: true),
            new Field('effectiveTo', 'c.effective_to::text'),
            // in effect today in the tenant's time zone (D-044)
            new Field('current', '(c.effective_from <= (now() at time zone t.timezone)::date and (c.effective_to is null or c.effective_to >= (now() at time zone t.timezone)::date))', Field::BOOLEAN, filterable: true),
            new Field('updatedAt', 'c.updated_at', Field::INSTANT),
            new Field('version', 'c.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false], ['effectiveFrom', true]];
    }
}
