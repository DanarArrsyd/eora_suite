<?php

declare(strict_types=1);

namespace App\Modules\Production\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `production.spk` — SPK per Line (doc 38, D-138): one row per SPK revision with its line, production day, number and
 * status. Read with `production.spk.view` (production and planning) inside the plants of that scope.
 */
final class SpkQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'RELEASED', 'CLOSED', 'CANCELLED', 'SUPERSEDED'];

    public const array STATUS_LABELS = ['DRAFT' => 'Draft', 'RELEASED' => 'Released', 'CLOSED' => 'Closed', 'CANCELLED' => 'Cancelled', 'SUPERSEDED' => 'Superseded'];

    public function resource(): string
    {
        return 'production.spk';
    }

    public function permission(): string
    {
        return 'production.spk.view';
    }

    public function permissions(): array
    {
        return ['production.spk.view', 'production.spk.create'];
    }

    public function from(): string
    {
        return 'spk_headers h
                 join plants p on p.tenant_id = h.tenant_id and p.id = h.plant_id
                 join work_centers wc on wc.tenant_id = h.tenant_id and wc.id = h.work_center_id
                 left join users ur on ur.id = h.released_by
                 left join lateral (select count(*) as n from spk_lines l where l.tenant_id = h.tenant_id and l.spk_id = h.id) l on true';
    }

    public function filterFrom(): string
    {
        return 'spk_headers h
                 join plants p on p.tenant_id = h.tenant_id and p.id = h.plant_id
                 join work_centers wc on wc.tenant_id = h.tenant_id and wc.id = h.work_center_id';
    }

    public function idColumn(): string
    {
        return 'h.id';
    }

    public function plantColumn(): string
    {
        return 'h.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'h.id', Field::UUID, filterable: true),
            new Field('code', 'h.spk_no', sortable: true, search: 'prefix'),
            new Field('status', 'h.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'h.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('workCenterId', 'h.work_center_id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true, search: 'prefix'),
            new Field('workCenterName', 'wc.name'),
            new Field('productionDate', 'h.production_date::text', sortable: true),
            new Field('revision', 'h.revision', Field::INTEGER, sortable: true),
            new Field('previousId', 'h.previous_id', Field::UUID),
            new Field('reference', 'h.reference', search: 'contains'),
            new Field('note', 'h.note'),
            new Field('revisionReason', 'h.revision_reason'),
            new Field('lineCount', 'l.n', Field::INTEGER),
            new Field('releasedAt', 'h.released_at', Field::INSTANT, sortable: true),
            new Field('releasedByName', 'ur.display_name'),
            new Field('createdAt', 'h.created_at', Field::INSTANT, sortable: true),
            new Field('version', 'h.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'SPK Line';
    }

    public function exportColumns(): array
    {
        return [
            new ExportColumn('SPK', 'code'),
            new ExportColumn('Revision', 'revision'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Line', 'workCenterCode'),
            new ExportColumn('Production date', 'productionDate'),
            new ExportColumn('Lines', 'lineCount'),
            new ExportColumn('Released by', 'releasedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['productionDate', true]];
    }
}
