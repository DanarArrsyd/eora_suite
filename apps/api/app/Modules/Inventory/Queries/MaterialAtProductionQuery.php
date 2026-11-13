<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.material_at_production` — material issued and not yet returned or consumed, per work center, work order,
 * item and lot (M4-D3, BR-ISS-02): the sum of the ledger legs that name the WO at the work center's Material at Production
 * location. Physical stock, not WIP (CLAUDE.md). Read with `inventory.stock.view`, `materials.issue.view` or
 * `materials.return.view` inside the plants of those scopes.
 */
final class MaterialAtProductionQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'inventory.material_at_production';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function permissions(): array
    {
        return ['inventory.stock.view', 'materials.issue.view', 'materials.return.view'];
    }

    public function from(): string
    {
        return "(select m.tenant_id, m.plant_id, m.location_id, m.wo_id, m.item_id, m.lot_id, m.uom_id, sum(m.qty_signed) as qty, max(m.created_at) as last_moved_at
                   from inventory_movements m join locations ml on ml.tenant_id = m.tenant_id and ml.id = m.location_id and ml.location_type = 'MAT_AT_PROD'
                  where m.wo_id is not null and m.stock_status = 'UNRESTRICTED'
                  group by m.tenant_id, m.plant_id, m.location_id, m.wo_id, m.item_id, m.lot_id, m.uom_id
                 having sum(m.qty_signed) <> 0) s
                 join locations l on l.tenant_id = s.tenant_id and l.id = s.location_id
                 join work_centers wc on wc.tenant_id = l.tenant_id and wc.id = l.work_center_id
                 join work_orders w on w.tenant_id = s.tenant_id and w.id = s.wo_id
                 join items i on i.tenant_id = s.tenant_id and i.id = s.item_id
                 join uoms u on u.tenant_id = s.tenant_id and u.id = s.uom_id
                 join plants p on p.tenant_id = s.tenant_id and p.id = s.plant_id
                 left join lots lt on lt.tenant_id = s.tenant_id and lt.id = s.lot_id";
    }

    public function idColumn(): string
    {
        return "md5(s.wo_id::text || s.location_id::text || s.item_id::text || coalesce(s.lot_id::text, ''))::uuid";
    }

    public function plantColumn(): string
    {
        return 's.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', $this->idColumn(), Field::UUID, filterable: true),
            new Field('code', 'w.wo_no', sortable: true, search: 'prefix'),
            new Field('plantId', 's.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('workCenterId', 'wc.id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true),
            new Field('locationId', 's.location_id', Field::UUID, filterable: true),
            new Field('locationCode', 'l.code'),
            new Field('workOrderId', 's.wo_id', Field::UUID, filterable: true),
            new Field('workOrderCode', 'w.wo_no', sortable: true),
            new Field('workOrderStatus', 'w.status'),
            new Field('itemId', 's.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('lotId', 's.lot_id', Field::UUID, filterable: true),
            new Field('lotNo', 'lt.lot_no', sortable: true),
            new Field('qty', 'trim_scale(s.qty)::text'),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('lastMovedAt', 's.last_moved_at', Field::INSTANT, sortable: true),
            new Field('version', '1', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Material at Production';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Work order', 'workOrderCode'),
            new ExportColumn('Material', 'itemCode'),
            new ExportColumn('Material name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Quantity', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['workCenterCode', false], ['workOrderCode', false], ['itemCode', false], ['lotNo', false]];
    }
}
