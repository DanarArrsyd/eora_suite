<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.conversion_profile_versions` — every version of the tenant's conversion profiles with its pair and formula
 * (`master.conversion.view`; tenant-wide, D-080). Inputs are returned by the version endpoint, not in the list.
 */
final class ConversionProfileVersionsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.conversion_profile_versions';
    }

    public function permission(): string
    {
        return 'master.conversion.view';
    }

    public function from(): string
    {
        return 'conversion_profile_versions v
                 join conversion_profiles p on p.tenant_id = v.tenant_id and p.id = v.profile_id
                 join items i on i.tenant_id = p.tenant_id and i.id = p.item_id
                 join items m on m.tenant_id = p.tenant_id and m.id = p.input_item_id
                 join tenants t on t.id = v.tenant_id
                 left join users up on up.id = v.prepared_by
                 left join users uv on uv.id = v.validated_by';
    }

    public function idColumn(): string
    {
        return 'v.id';
    }

    public function fields(): array
    {
        $today = '(now() at time zone t.timezone)::date';

        return [
            new Field('id', 'v.id', Field::UUID, filterable: true),
            new Field('profileId', 'v.profile_id', Field::UUID, filterable: true),
            new Field('itemId', 'p.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('inputItemId', 'p.input_item_id', Field::UUID, filterable: true),
            new Field('inputItemCode', 'm.code', sortable: true, search: 'prefix'),
            new Field('inputItemName', 'm.name'),
            new Field('inputForm', 'v.input_form', Field::ENUM, filterable: true, options: ['SHEET', 'COIL']),
            new Field('profileStatus', 'p.status', Field::ENUM, options: ['ACTIVE', 'INACTIVE']),
            new Field('formulaCode', 'v.formula_code', Field::ENUM, filterable: true, options: ['SHEET_V1', 'COIL_V1']),
            new Field('versionNo', 'v.version_no', Field::INTEGER, sortable: true),
            new Field('status', 'v.status', Field::ENUM, sortable: true, filterable: true, options: ItemWeightsQuery::STATUSES),
            new Field('effectiveFrom', 'v.effective_from::text', sortable: true),
            new Field('effectiveTo', 'v.effective_to::text'),
            new Field('inUse', "(v.status in ('VALIDATED', 'RETIRED') and v.effective_from <= {$today} and (v.effective_to is null or v.effective_to >= {$today}))", Field::BOOLEAN),
            new Field('changeReason', 'v.change_reason'),
            new Field('rejectReason', 'v.reject_reason'),
            new Field('preparedByName', 'up.display_name'),
            new Field('validatedByName', 'uv.display_name'),
            new Field('validatedAt', 'v.validated_at', Field::INSTANT),
            new Field('retiredAt', 'v.retired_at', Field::INSTANT),
            new Field('updatedAt', 'v.updated_at', Field::INSTANT),
            new Field('version', 'v.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['inputItemCode', false], ['versionNo', true]];
    }
}
