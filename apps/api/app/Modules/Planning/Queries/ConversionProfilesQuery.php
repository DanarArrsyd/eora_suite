<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.conversion_profiles` — one per (part, input material) pair (D-080) with the version in force today, for
 * pickers such as the BOM line (`master.conversion.view`).
 */
final class ConversionProfilesQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.conversion_profiles';
    }

    public function permission(): string
    {
        return 'master.conversion.view';
    }

    public function from(): string
    {
        return 'conversion_profiles p
                 join items i on i.tenant_id = p.tenant_id and i.id = p.item_id
                 join items m on m.tenant_id = p.tenant_id and m.id = p.input_item_id
                 join tenants t on t.id = p.tenant_id';
    }

    public function idColumn(): string
    {
        return 'p.id';
    }

    public function fields(): array
    {
        $current = "(select v.%s from conversion_profile_versions v
                      where v.profile_id = p.id and v.status in ('VALIDATED', 'RETIRED')
                        and v.effective_from <= (now() at time zone t.timezone)::date
                        and (v.effective_to is null or v.effective_to >= (now() at time zone t.timezone)::date))";

        return [
            new Field('id', 'p.id', Field::UUID, filterable: true),
            new Field('itemId', 'p.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('inputItemId', 'p.input_item_id', Field::UUID, filterable: true),
            new Field('inputItemCode', 'm.code', sortable: true, search: 'prefix'),
            new Field('inputItemName', 'm.name'),
            new Field('inputForm', 'p.input_form', Field::ENUM, filterable: true, options: ['SHEET', 'COIL']),
            new Field('status', 'p.status', Field::ENUM, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('versionInUseId', sprintf($current, 'id'), Field::UUID),
            new Field('versionInUseNo', sprintf($current, 'version_no'), Field::INTEGER),
            new Field('versionCount', '(select count(*) from conversion_profile_versions v where v.profile_id = p.id)', Field::INTEGER),
            new Field('updatedAt', 'p.updated_at', Field::INSTANT),
            new Field('version', 'p.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['inputItemCode', false]];
    }
}
