<?php

use App\Modules\Core\Http\OrgController;
use App\Modules\Core\Http\PartController;
use App\Modules\Core\Http\PartnerController;
use App\Modules\Core\Http\ResourceController;
use App\Modules\Delivery\Http\DeliveryController;
use App\Modules\Demand\Http\SalesOrderController;
use App\Modules\Inventory\Http\AdjustmentController;
use App\Modules\Inventory\Http\LotSplitController;
use App\Modules\Inventory\Http\MaterialFlowController;
use App\Modules\Inventory\Http\PeriodController;
use App\Modules\Inventory\Http\ReceiptController;
use App\Modules\Inventory\Http\ReversalController;
use App\Modules\Inventory\Http\TransferController;
use App\Modules\Planning\Http\BomController;
use App\Modules\Planning\Http\BomRoutingMapController;
use App\Modules\Planning\Http\ConversionProfileController;
use App\Modules\Planning\Http\ItemWeightController;
use App\Modules\Planning\Http\RoutingController;
use App\Modules\Planning\Http\WoCloseExceptionController;
use App\Modules\Planning\Http\WorkOrderController;
use App\Modules\Production\Http\ProductionController;
use App\Modules\Quality\Http\QualityController;
use App\Modules\Warehouse\Http\FgReceiptController;
use App\Platform\Access\Http\AccessController;
use App\Platform\Access\Http\MemberController;
use App\Platform\Auth\Http\AuthController;
use App\Platform\Export\ExportController;
use App\Platform\Http\Controllers\HealthController;
use App\Platform\Import\ImportController;
use App\Platform\Inbox\Http\InboxController;
use App\Platform\Query\Http\QueryController;
use Illuminate\Support\Facades\Route;

/*
| API v1 (doc 24 §2). Business resources arrive with their slices (doc 11); B0 exposes health only.
*/
Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('health', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

    // Authentication (doc 25 §4, ADR-0006). Stateful SPA: GET /sanctum/csrf-cookie first, then X-XSRF-TOKEN.
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('tenant', [AuthController::class, 'selectTenant'])->name('tenant');
        Route::post('plant', [AuthController::class, 'selectPlant'])->name('plant');
        Route::post('invitations/accept', [AuthController::class, 'acceptInvitation'])->middleware('throttle:invitation-accept')->name('invitations.accept');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:invitation-accept')->name('password.reset');
    });

    // Inbox of the signed-in user in the active tenant (doc 07, doc 24 §7).
    Route::middleware('tenant')->group(function (): void {
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('inbox/read', [InboxController::class, 'markRead'])->name('inbox.read');
    });

    // Administration → Users, Roles & Permissions (doc 25 §2.4, §3, §13; doc 18). One TenantTransaction per request.
    Route::prefix('core')->name('core.')->middleware('tenant')->group(function (): void {
        Route::get('roles', [AccessController::class, 'roles'])->name('roles.index');
        Route::get('members', [AccessController::class, 'members'])->name('members.index');
        Route::post('members/invitations', [MemberController::class, 'invite'])->middleware('throttle:member-invite')->name('members.invite');
        Route::post('members/{membership}/actions/resend-invitation', [MemberController::class, 'resendInvitation'])->middleware('throttle:member-invite')->name('members.resend-invitation');
        Route::post('members/{membership}/actions/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
        Route::post('members/{membership}/actions/reactivate', [MemberController::class, 'reactivate'])->name('members.reactivate');
        Route::post('role-assignments', [AccessController::class, 'assign'])->name('role-assignments.store');
        Route::post('role-assignments/{assignment}/actions/revoke', [AccessController::class, 'revoke'])->name('role-assignments.revoke');

        // Master Data → organisation (doc 29 §1.1); lists are the core.* query resources
        Route::post('companies', [OrgController::class, 'storeCompany'])->name('companies.store');
        Route::patch('companies/{company}', [OrgController::class, 'updateCompany'])->name('companies.update');
        Route::post('plants', [OrgController::class, 'storePlant'])->name('plants.store');
        Route::patch('plants/{plant}', [OrgController::class, 'updatePlant'])->name('plants.update');
        Route::post('warehouses', [OrgController::class, 'storeWarehouse'])->name('warehouses.store');
        Route::patch('warehouses/{warehouse}', [OrgController::class, 'updateWarehouse'])->name('warehouses.update');
        Route::post('locations', [OrgController::class, 'storeLocation'])->name('locations.store');
        Route::patch('locations/{location}', [OrgController::class, 'updateLocation'])->name('locations.update');
        Route::post('work-centers', [ResourceController::class, 'storeWorkCenter'])->name('work-centers.store');
        Route::patch('work-centers/{workCenter}', [ResourceController::class, 'updateWorkCenter'])->name('work-centers.update');
        Route::post('machines', [ResourceController::class, 'storeMachine'])->name('machines.store');
        Route::patch('machines/{machine}', [ResourceController::class, 'updateMachine'])->name('machines.update');
        Route::post('dies', [ResourceController::class, 'storeDie'])->name('dies.store');
        Route::patch('dies/{die}', [ResourceController::class, 'updateDie'])->name('dies.update');

        // Master Data → part master (doc 29 §1.2)
        Route::post('uoms', [PartController::class, 'storeUom'])->name('uoms.store');
        Route::patch('uoms/{uom}', [PartController::class, 'updateUom'])->name('uoms.update');
        Route::post('items', [PartController::class, 'storeItem'])->name('items.store');
        Route::patch('items/{item}', [PartController::class, 'updateItem'])->name('items.update');
        Route::put('items/{item}/material-spec', [PartController::class, 'putMaterialSpec'])->name('items.material-spec');
        Route::post('uom-conversions', [PartController::class, 'storeUomConversion'])->name('uom-conversions.store');
        Route::post('die-items', [PartController::class, 'storeDieItem'])->name('die-items.store');
        Route::patch('die-items/{dieItem}', [PartController::class, 'updateDieItem'])->name('die-items.update');

        // Master Data → partners (doc 29 §1.2)
        Route::post('customers', [PartnerController::class, 'storeCustomer'])->name('customers.store');
        Route::patch('customers/{customer}', [PartnerController::class, 'updateCustomer'])->name('customers.update');
        Route::post('suppliers', [PartnerController::class, 'storeSupplier'])->name('suppliers.store');
        Route::patch('suppliers/{supplier}', [PartnerController::class, 'updateSupplier'])->name('suppliers.update');
        Route::post('customer-items', [PartnerController::class, 'storeCustomerItem'])->name('customer-items.store');
        Route::patch('customer-items/{customerItem}', [PartnerController::class, 'updateCustomerItem'])->name('customer-items.update');
    });

    // Master Data → routings (doc 29 §1.3, doc 26 §2.3–§2.4a); lists are the planning.* query resources
    Route::prefix('planning')->name('planning.')->middleware('tenant')->group(function (): void {
        Route::post('process-types', [RoutingController::class, 'storeProcessType'])->name('process-types.store');
        Route::patch('process-types/{processType}', [RoutingController::class, 'updateProcessType'])->name('process-types.update');
        Route::post('routings', [RoutingController::class, 'storeRouting'])->name('routings.store');
        Route::patch('routings/{routing}', [RoutingController::class, 'updateRouting'])->name('routings.update');
        Route::post('routings/{routing}/versions', [RoutingController::class, 'storeVersion'])->name('routings.versions.store');
        Route::get('routing-versions/{version}', [RoutingController::class, 'showVersion'])->name('routing-versions.show');
        Route::patch('routing-versions/{version}', [RoutingController::class, 'updateVersion'])->name('routing-versions.update');
        Route::put('routing-versions/{version}/operations', [RoutingController::class, 'putOperations'])->name('routing-versions.operations');
        Route::post('routing-versions/{version}/actions/submit', [RoutingController::class, 'submit'])->name('routing-versions.submit');
        Route::post('routing-versions/{version}/actions/withdraw', [RoutingController::class, 'withdraw'])->name('routing-versions.withdraw');
        Route::post('routing-versions/{version}/actions/reject', [RoutingController::class, 'reject'])->name('routing-versions.reject');
        Route::post('routing-versions/{version}/actions/reopen', [RoutingController::class, 'reopen'])->name('routing-versions.reopen');
        Route::post('routing-versions/{version}/actions/approve', [RoutingController::class, 'approve'])->name('routing-versions.approve');
        // BOMs (doc 29 §1.4, doc 26 §2.5; D-078 any scope) and BOM–routing maps (D-079; routing plant scope)
        Route::post('bom-versions', [BomController::class, 'storeVersion'])->name('bom-versions.store');
        Route::get('bom-versions/{version}', [BomController::class, 'showVersion'])->name('bom-versions.show');
        Route::patch('bom-versions/{version}', [BomController::class, 'updateVersion'])->name('bom-versions.update');
        Route::put('bom-versions/{version}/lines', [BomController::class, 'putLines'])->name('bom-versions.lines');
        Route::post('bom-versions/{version}/actions/submit', [BomController::class, 'submit'])->name('bom-versions.submit');
        Route::post('bom-versions/{version}/actions/withdraw', [BomController::class, 'withdraw'])->name('bom-versions.withdraw');
        Route::post('bom-versions/{version}/actions/reject', [BomController::class, 'reject'])->name('bom-versions.reject');
        Route::post('bom-versions/{version}/actions/reopen', [BomController::class, 'reopen'])->name('bom-versions.reopen');
        Route::post('bom-versions/{version}/actions/approve', [BomController::class, 'approve'])->name('bom-versions.approve');
        Route::post('bom-routing-maps', [BomRoutingMapController::class, 'store'])->name('bom-routing-maps.store');
        Route::get('bom-routing-maps/{map}', [BomRoutingMapController::class, 'show'])->name('bom-routing-maps.show');
        Route::put('bom-routing-maps/{map}/lines', [BomRoutingMapController::class, 'putLines'])->name('bom-routing-maps.lines');
        Route::post('bom-routing-maps/{map}/actions/submit', [BomRoutingMapController::class, 'submit'])->name('bom-routing-maps.submit');
        Route::post('bom-routing-maps/{map}/actions/withdraw', [BomRoutingMapController::class, 'withdraw'])->name('bom-routing-maps.withdraw');
        Route::post('bom-routing-maps/{map}/actions/reject', [BomRoutingMapController::class, 'reject'])->name('bom-routing-maps.reject');
        Route::post('bom-routing-maps/{map}/actions/reopen', [BomRoutingMapController::class, 'reopen'])->name('bom-routing-maps.reopen');
        Route::post('bom-routing-maps/{map}/actions/approve', [BomRoutingMapController::class, 'approve'])->name('bom-routing-maps.approve');

        // Work Orders outside MPS (doc 33 §1.2, D-109, G-MPS-6): drafted by PPC, released by a second person
        Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
        Route::get('work-orders/{order}', [WorkOrderController::class, 'show'])->name('work-orders.show');
        Route::patch('work-orders/{order}', [WorkOrderController::class, 'updateDraft'])->name('work-orders.update');
        Route::post('work-orders/{order}/actions/submit', [WorkOrderController::class, 'submit'])->name('work-orders.submit');
        Route::post('work-orders/{order}/actions/withdraw', [WorkOrderController::class, 'withdraw'])->name('work-orders.withdraw');
        Route::post('work-orders/{order}/actions/return', [WorkOrderController::class, 'returnToDraft'])->name('work-orders.return');
        Route::post('work-orders/{order}/actions/release', [WorkOrderController::class, 'release'])->name('work-orders.release');
        Route::post('work-orders/{order}/actions/cancel', [WorkOrderController::class, 'cancel'])->name('work-orders.cancel');
        // D-117: close, and the close exception for material left at production (PPC asks, CSA/INV decides)
        Route::post('work-orders/{order}/actions/close', [WorkOrderController::class, 'close'])->name('work-orders.close');
        Route::post('work-orders/{order}/close-exceptions', [WoCloseExceptionController::class, 'store'])->name('work-orders.close-exceptions.store');
        Route::post('wo-close-exceptions/{exception}/approve', [WoCloseExceptionController::class, 'approve'])->name('wo-close-exceptions.approve');
        Route::post('wo-close-exceptions/{exception}/reject', [WoCloseExceptionController::class, 'reject'])->name('wo-close-exceptions.reject');

        // Conversion data (doc 29 §1.5, D-080; tenant-wide, master.conversion.*): net weights and conversion profiles
        Route::post('item-weights', [ItemWeightController::class, 'store'])->name('item-weights.store');
        Route::get('item-weights/{weight}', [ItemWeightController::class, 'show'])->name('item-weights.show');
        Route::patch('item-weights/{weight}', [ItemWeightController::class, 'updateWeight'])->name('item-weights.update');
        Route::post('item-weights/{weight}/actions/submit', [ItemWeightController::class, 'submit'])->name('item-weights.submit');
        Route::post('item-weights/{weight}/actions/withdraw', [ItemWeightController::class, 'withdraw'])->name('item-weights.withdraw');
        Route::post('item-weights/{weight}/actions/reject', [ItemWeightController::class, 'reject'])->name('item-weights.reject');
        Route::post('item-weights/{weight}/actions/reopen', [ItemWeightController::class, 'reopen'])->name('item-weights.reopen');
        Route::post('item-weights/{weight}/actions/validate', [ItemWeightController::class, 'validate'])->name('item-weights.validate');
        Route::post('item-weights/{weight}/actions/retire', [ItemWeightController::class, 'retire'])->name('item-weights.retire');
        Route::get('conversion-formulas', [ConversionProfileController::class, 'formulas'])->name('conversion-formulas.index');
        Route::patch('conversion-profiles/{profile}', [ConversionProfileController::class, 'updateProfile'])->name('conversion-profiles.update');
        Route::post('conversion-profile-versions', [ConversionProfileController::class, 'storeVersion'])->name('conversion-profile-versions.store');
        Route::get('conversion-profile-versions/{version}', [ConversionProfileController::class, 'showVersion'])->name('conversion-profile-versions.show');
        Route::patch('conversion-profile-versions/{version}', [ConversionProfileController::class, 'updateVersion'])->name('conversion-profile-versions.update');
        Route::post('conversion-profile-versions/{version}/actions/submit', [ConversionProfileController::class, 'submit'])->name('conversion-profile-versions.submit');
        Route::post('conversion-profile-versions/{version}/actions/withdraw', [ConversionProfileController::class, 'withdraw'])->name('conversion-profile-versions.withdraw');
        Route::post('conversion-profile-versions/{version}/actions/reject', [ConversionProfileController::class, 'reject'])->name('conversion-profile-versions.reject');
        Route::post('conversion-profile-versions/{version}/actions/reopen', [ConversionProfileController::class, 'reopen'])->name('conversion-profile-versions.reopen');
        Route::post('conversion-profile-versions/{version}/actions/validate', [ConversionProfileController::class, 'validate'])->name('conversion-profile-versions.validate');
        Route::post('conversion-profile-versions/{version}/actions/retire', [ConversionProfileController::class, 'retire'])->name('conversion-profile-versions.retire');
    });

    // Sales Orders (doc 33 §1.1, D-107): drafts edited by PPC, confirmed into FIRM schedule lines; changes after that add or cancel
    Route::prefix('demand')->name('demand.')->middleware('tenant')->group(function (): void {
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
        Route::get('sales-orders/{order}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
        Route::patch('sales-orders/{order}', [SalesOrderController::class, 'updateNote'])->name('sales-orders.update');
        Route::put('sales-orders/{order}/lines', [SalesOrderController::class, 'putLines'])->name('sales-orders.lines');
        Route::post('sales-orders/{order}/actions/confirm', [SalesOrderController::class, 'confirm'])->name('sales-orders.confirm');
        Route::post('sales-orders/{order}/actions/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
        Route::post('sales-orders/{order}/lines', [SalesOrderController::class, 'addLine'])->name('sales-orders.lines.store');
        Route::post('sales-orders/{order}/schedule-lines', [SalesOrderController::class, 'addSchedule'])->name('sales-orders.schedule-lines.store');
        Route::post('sales-orders/{order}/schedule-lines/{schedule}/actions/cancel', [SalesOrderController::class, 'cancelSchedule'])->name('sales-orders.schedule-lines.cancel');
    });

    // Inventory postings (doc 32 §1.3, ADR-0014): one tenant transaction, retried on deadlock (the posting is idempotent)
    Route::prefix('inventory')->name('inventory.')->middleware(['tenant:retry'])->group(function (): void {
        Route::post('receipts', [ReceiptController::class, 'receive'])->name('receipts.store');
        Route::post('incoming-releases', [ReceiptController::class, 'release'])->name('incoming-releases.store');
        Route::post('transfers', [TransferController::class, 'transfer'])->name('transfers.store');
        Route::post('lot-splits', [LotSplitController::class, 'store'])->name('lot-splits.store');
        Route::post('adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
        Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->name('adjustments.reject');
        Route::post('material-issues', [MaterialFlowController::class, 'issue'])->name('material-issues.store');
        Route::post('material-returns', [MaterialFlowController::class, 'requestReturn'])->name('material-returns.store');
        Route::post('material-returns/{return}/receive', [MaterialFlowController::class, 'receive'])->name('material-returns.receive');
        Route::post('material-returns/{return}/refuse', [MaterialFlowController::class, 'refuse'])->name('material-returns.refuse');
        Route::post('reversals', [ReversalController::class, 'store'])->name('reversals.store');
        Route::post('reversals/{reversal}/approve', [ReversalController::class, 'approve'])->name('reversals.approve');
        Route::post('reversals/{reversal}/reject', [ReversalController::class, 'reject'])->name('reversals.reject');
        Route::post('periods/close-requests', [PeriodController::class, 'requestClose'])->name('periods.close-request');
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->name('periods.close');
        Route::post('periods/{period}/close-return', [PeriodController::class, 'returnClose'])->name('periods.close-return');
        Route::post('periods/{period}/reopen-requests', [PeriodController::class, 'requestReopen'])->name('periods.reopen-request');
        Route::post('periods/{period}/reopen', [PeriodController::class, 'reopen'])->name('periods.reopen');
        Route::post('periods/{period}/reopen-return', [PeriodController::class, 'returnReopen'])->name('periods.reopen-return');
    });

    // Production → runs and production postings (doc 33 §1.4, D-111): one tenant transaction, retried on deadlock (postings are idempotent)
    Route::prefix('production')->name('production.')->middleware(['tenant:retry'])->group(function (): void {
        Route::post('runs', [ProductionController::class, 'open'])->name('runs.store');
        Route::get('runs/{run}', [ProductionController::class, 'show'])->name('runs.show');
        Route::post('runs/{run}/actions/close', [ProductionController::class, 'close'])->name('runs.close');
        Route::post('runs/{run}/actions/cancel', [ProductionController::class, 'cancel'])->name('runs.cancel');
        Route::post('postings', [ProductionController::class, 'post'])->name('postings.store');
    });

    // Quality → disposition (doc 33 §1.5, D-113): one tenant transaction, retried on deadlock (postings are idempotent)
    Route::prefix('quality')->name('quality.')->middleware(['tenant:retry'])->group(function (): void {
        Route::post('dispositions', [QualityController::class, 'dispose'])->name('dispositions.store');
        Route::post('release-requests', [QualityController::class, 'requestRelease'])->name('release-requests.store');
        Route::post('release-requests/{release}/approve', [QualityController::class, 'approve'])->name('release-requests.approve');
        Route::post('release-requests/{release}/reject', [QualityController::class, 'reject'])->name('release-requests.reject');
    });

    // Warehouse → FG receipt (doc 33 §1.6, D-114): one tenant transaction, retried on deadlock (postings are idempotent)
    Route::prefix('warehouse')->name('warehouse.')->middleware(['tenant:retry'])->group(function (): void {
        Route::post('fg-receipts', [FgReceiptController::class, 'store'])->name('fg-receipts.store');
    });

    // Delivery → FG allocation and pick (doc 33 §1.7, D-115): one tenant transaction, retried on deadlock (writes are idempotent)
    Route::prefix('delivery')->name('delivery.')->middleware(['tenant:retry'])->group(function (): void {
        Route::get('schedule-lines/{line}', [DeliveryController::class, 'show'])->name('schedule-lines.show');
        Route::get('schedule-lines/{line}/proposal', [DeliveryController::class, 'proposal'])->name('schedule-lines.proposal');
        Route::post('allocations', [DeliveryController::class, 'allocate'])->name('allocations.store');
        Route::post('allocations/{allocation}/release', [DeliveryController::class, 'release'])->name('allocations.release');
        Route::post('picks', [DeliveryController::class, 'pick'])->name('picks.store');
        Route::post('unpicks', [DeliveryController::class, 'unpick'])->name('unpicks.store');
        Route::get('staged', [DeliveryController::class, 'staged'])->name('staged.index');
        Route::post('shipments', [DeliveryController::class, 'ship'])->name('shipments.store');
    });

    // Query resources (ADR-0008, doc 24 §3): one read-only TenantTransaction; resources registered in config/eora.php
    Route::prefix('query')->name('query.')->middleware('tenant')->group(function (): void {
        Route::get('{resource}/schema', [QueryController::class, 'schema'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('schema');
        Route::get('{resource}', [QueryController::class, 'index'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('index');
    });

    // Data exchange → Import (doc 29 §1.6, doc 26 §5, ADR-0017, D-081): upload, preview, error file, submit, commit
    Route::prefix('exchange')->name('exchange.')->middleware('tenant')->group(function (): void {
        Route::get('import-templates', [ImportController::class, 'templates'])->name('import-templates.index');
        Route::get('import-templates/{template}/file', [ImportController::class, 'templateFile'])->name('import-templates.file');
        Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{job}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{job}/rows', [ImportController::class, 'rows'])->name('imports.rows');
        Route::get('imports/{job}/error-file', [ImportController::class, 'errorFile'])->name('imports.error-file');
        Route::post('imports/{job}/actions/submit', [ImportController::class, 'submit'])->name('imports.submit');
        Route::post('imports/{job}/actions/commit', [ImportController::class, 'commit'])->name('imports.commit');
        Route::post('imports/{job}/actions/reject', [ImportController::class, 'reject'])->name('imports.reject');
        // Data exchange → Export (doc 08, doc 24 §3, D-092): queued job, signed 15-minute link, file kept 24 hours
        Route::post('exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('exports/{job}', [ExportController::class, 'show'])->name('exports.show');
        Route::post('exports/{job}/link', [ExportController::class, 'link'])->name('exports.link');
        Route::get('exports/{job}/file', [ExportController::class, 'file'])->middleware('signed:relative')->name('exports.file');
    });
});
