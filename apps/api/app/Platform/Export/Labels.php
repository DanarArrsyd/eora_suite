<?php

declare(strict_types=1);

namespace App\Platform\Export;

/**
 * Screen labels of the enum values master lists show (the same words as the web's option lists and status badges),
 * so an exported cell reads like the screen: "Finished good", not FINISHED.
 */
final class Labels
{
    public const array MASTER_STATUS = ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive', 'MAINTENANCE' => 'In maintenance', 'OBSOLETE' => 'Obsolete'];

    public const array VERSION_STATUS = [
        'DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'REJECTED' => 'Rejected', 'APPROVED' => 'Approved', 'SUPERSEDED' => 'Superseded',
        'VALIDATED' => 'Validated', 'RETIRED' => 'Retired',
    ];

    public const array WAREHOUSE_TYPES = ['RAW_MATERIAL' => 'Raw material', 'FINISHED_GOODS' => 'Finished goods', 'GENERAL' => 'General'];

    public const array LOCATION_TYPES = [
        'RM_WAREHOUSE' => 'Raw material storage', 'FG_WAREHOUSE' => 'Finished goods storage', 'QC_AREA' => 'QC area', 'NG_AREA' => 'NG area',
        'SCRAP_YARD' => 'Scrap yard', 'STAGING' => 'Staging', 'CUSTODY_TRANSIT' => 'Custody transit', 'MAT_AT_PROD' => 'Material at Production',
        'V_SUPPLIER' => 'Supplier (virtual)', 'V_CUSTOMER' => 'Customer (virtual)', 'V_CONSUMED' => 'Consumed (virtual)',
        'V_PRODUCED' => 'Produced (virtual)', 'V_ADJUSTMENT' => 'Adjustment (virtual)', 'V_OPENING' => 'Opening balance (virtual)',
    ];

    public const array DIE_TYPES = ['DIE' => 'Die', 'JIG' => 'Jig', 'FIXTURE' => 'Fixture'];

    public const array UOM_CLASSES = ['MASS' => 'Mass', 'COUNT' => 'Count', 'LENGTH' => 'Length', 'AREA' => 'Area', 'VOLUME' => 'Volume'];

    /** Semi-Finished Material: an intermediate stage item with its own stock (D-059), never "WIP". */
    public const array ITEM_TYPES = [
        'RAW' => 'Raw material', 'SEMI_FINISHED' => 'Semi-Finished Material', 'FINISHED' => 'Finished good', 'SCRAP' => 'Scrap', 'CONSUMABLE' => 'Consumable',
    ];

    public const array PROCESS_CATEGORIES = ['TRANSFORMATION' => 'Transformation', 'INSPECTION' => 'Inspection', 'HANDLING' => 'Handling'];

    public const array ROUTING_KINDS = ['PRIMARY' => 'Primary', 'ALTERNATE' => 'Alternate'];

    public const array INPUT_FORMS = ['COIL' => 'Coil', 'SHEET' => 'Sheet'];
}
