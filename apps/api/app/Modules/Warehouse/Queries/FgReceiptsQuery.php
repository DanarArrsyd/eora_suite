<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `warehouse.fg_receipts` — FG receipts (doc 04 row 10, D-114), one row per receipt document with the FG location it
 * received into, its lines and the parts and lots received. Read with `warehouse.fg_receipt.view` inside the plants of
 * its scopes.
 */
final class FgReceiptsQuery extends QueryDefinition
{
    public const array STATUSES = ['POSTED', 'REVERSED'];

    public const array STATUS_LABELS = ['POSTED' => 'Posted', 'REVERSED' => 'Reversed'];

    public function resource(): string
    {
        return 'warehouse.fg_receipts';
    }

    public function permission(): string
    {
        return 'warehouse.fg_receipt.view';
    }

    public function from(): string
    {
        // the received legs (into the FG warehouse) name the location and the lots (one location per receipt, D-114)
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'FG_RECEIPT'
                 cross join lateral (
                   select min(m.location_id::text)::uuid as location_id, count(*) as lines,
                          string_agg(distinct i.code, ', ') as parts,
                          string_agg(i.code || ' ' || coalesce(lt.lot_no, 'no lot') || ' ' || trim_scale(m.qty_signed)::text || ' ' || u.code, '; ' order by m.leg_no) as received
                     from inventory_movements m
                     join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                     join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                     left join lots lt on lt.tenant_id = m.tenant_id and lt.id = m.lot_id
                    where m.tenant_id = d.tenant_id and m.document_id = d.id and m.qty_signed > 0) x
                 left join locations l on l.tenant_id = d.tenant_id and l.id = x.location_id
                 left join users up on up.id = d.posted_by";
    }

    public function filterFrom(): string
    {
        return "inventory_documents d
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id and d.doc_type = 'FG_RECEIPT'";
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
            new Field('locationCode', 'l.code', sortable: true),
            new Field('lines', 'x.lines', Field::INTEGER),
            new Field('parts', 'x.parts'),
            new Field('received', 'x.received'),
            new Field('productionDate', 'd.production_date::text', sortable: true),
            new Field('note', 'd.note'),
            new Field('postedByName', 'up.display_name'),
            new Field('postedAt', 'd.posted_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'FG receipts';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Receipt', 'code'),
            new ExportColumn('Status', 'status', labels: self::STATUS_LABELS),
            new ExportColumn('Date', 'productionDate', ExportColumn::DATE),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('FG location', 'locationCode'),
            new ExportColumn('Lines', 'lines', ExportColumn::INTEGER),
            new ExportColumn('Received', 'received'),
            new ExportColumn('Note', 'note'),
            new ExportColumn('Posted by', 'postedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['postedAt', true]];
    }
}
