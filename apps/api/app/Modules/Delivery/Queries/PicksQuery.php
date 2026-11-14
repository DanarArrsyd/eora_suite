<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `delivery.picks` — picks to staging and unpicks back to the FG warehouse (doc 04 row 11, D-115), one row per document
 * with its direction, lines and what moved. Read with `delivery.shipment.view` inside the plants of its scopes.
 */
final class PicksQuery extends QueryDefinition
{
    public const array STATUSES = ['POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'delivery.picks';
    }

    public function permission(): string
    {
        return 'delivery.shipment.view';
    }

    public function from(): string
    {
        // the legs into the destination tell the direction (staging = pick, FG warehouse = unpick) and what moved
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'PICK'
                 cross join lateral (
                   select bool_or(l.location_type = 'STAGING') as to_staging, count(*) as lines,
                          string_agg(i.code || ' ' || coalesce(lt.lot_no, 'no lot') || ' ' || trim_scale(m.qty_signed)::text || ' ' || u.code || ' → ' || l.code, '; ' order by m.leg_no) as moved
                     from inventory_movements m
                     join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id
                     join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                     join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                     left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id
                    where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0) x
                 left join users up on up.id = d.posted_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'PICK'";
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
            new Field('code', 'd.doc_no', sortable: true, search: 'prefix'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('direction', "case when x.to_staging then 'PICK' else 'UNPICK' end"),
            new Field('lines', 'x.lines', Field::INTEGER),
            new Field('moved', 'x.moved'),
            new Field('note', 'd.note'),
            new Field('postedByName', 'up.display_name'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Picks';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Document', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Direction', 'direction', labels: ['PICK' => 'Pick to staging', 'UNPICK' => 'Back to FG']),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Lines', 'lines', ExportColumn::INTEGER),
            new ExportColumn('Moved', 'moved'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
