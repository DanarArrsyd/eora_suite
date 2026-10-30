<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.stock` — stock on hand per balance key (plant, location, item, lot, status) of the plants inside the
 * caller's `inventory.stock.view` scopes, in a warehouse or at a work center (Material at Production); empty balances
 * are left out. Available = UNRESTRICTED on hand less its active
 * allocations (BR-INV-08); other statuses are never available (D-043).
 */
final class StockQuery extends QueryDefinition
{
    public const array STATUSES = ['UNRESTRICTED', 'PENDING_QC', 'QC_RELEASED', 'HOLD', 'REWORK', 'REJECTED', 'BLOCKED'];

    public const array STATUS_LABELS = [
        'UNRESTRICTED' => 'Unrestricted', 'PENDING_QC' => 'Pending QC', 'QC_RELEASED' => 'QC released', 'HOLD' => 'Hold',
        'REWORK' => 'Rework', 'REJECTED' => 'Rejected', 'BLOCKED' => 'Blocked',
    ];

    public function resource(): string
    {
        return 'inventory.stock';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function from(): string
    {
        return 'stock_balances b
                 join items i on i.tenant_id = b.tenant_id and i.id = b.item_id and b.qty > 0
                 join locations l on l.tenant_id = b.tenant_id and l.id = b.location_id
                 left join warehouses w on w.tenant_id = l.tenant_id and w.id = l.warehouse_id
                 left join work_centers wc on wc.tenant_id = l.tenant_id and wc.id = l.work_center_id
                 join plants p on p.tenant_id = b.tenant_id and p.id = b.plant_id
                 join uoms u on u.tenant_id = b.tenant_id and u.id = b.uom_id
                 left join lots lt on lt.tenant_id = b.tenant_id and lt.id = b.lot_id';
    }

    public function idColumn(): string
    {
        return 'b.id';
    }

    public function plantColumn(): string
    {
        return 'b.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        $allocated = "coalesce((select sum(a.qty) from stock_allocations a where a.balance_id = b.id and a.status = 'ACTIVE'), 0)";

        return [
            new Field('id', 'b.id', Field::UUID, filterable: true),
            new Field('plantId', 'b.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('warehouseId', 'l.warehouse_id', Field::UUID, filterable: true),
            new Field('warehouseCode', 'w.code', sortable: true),
            // Material at Production lies at a work center, not in a warehouse (stock until consumed or returned)
            new Field('workCenterId', 'l.work_center_id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true),
            new Field('locationId', 'b.location_id', Field::UUID, filterable: true),
            new Field('locationCode', 'l.code', sortable: true),
            new Field('locationType', 'l.location_type', Field::ENUM, filterable: true, options: array_keys(Labels::LOCATION_TYPES)),
            new Field('itemId', 'b.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('b.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('b.item_id', 'name')),
            new Field('itemType', 'i.item_type', Field::ENUM, filterable: true, options: array_keys(Labels::ITEM_TYPES)),
            new Field('lotId', 'b.lot_id', Field::UUID, filterable: true),
            new Field('lotNo', 'lt.lot_no', sortable: true),
            new Field('supplierLotNo', 'lt.supplier_lot_no'),
            new Field('stockStatus', 'b.stock_status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('qty', 'trim_scale(b.qty)::text'),
            new Field('availableQty', "trim_scale(case when b.stock_status = 'UNRESTRICTED' then b.qty - {$allocated} else 0 end)::text"),
            new Field('uomCode', 'u.code'),
            new Field('uomDecimals', 'u.decimals', Field::INTEGER),
            new Field('updatedAt', 'b.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'b.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Stock';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Warehouse', 'warehouseCode'),
            new ExportColumn('Work center', 'workCenterCode'),
            new ExportColumn('Location', 'locationCode'),
            new ExportColumn('Item', 'itemCode'),
            new ExportColumn('Item name', 'itemName'),
            new ExportColumn('Lot', 'lotNo'),
            new ExportColumn('Supplier lot', 'supplierLotNo'),
            new ExportColumn('Status', 'stockStatus', labels: self::STATUS_LABELS),
            new ExportColumn('On hand', 'qty', ExportColumn::DECIMAL),
            new ExportColumn('Available', 'availableQty', ExportColumn::DECIMAL),
            new ExportColumn('Unit', 'uomCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['lotNo', false], ['locationCode', false]];
    }
}
