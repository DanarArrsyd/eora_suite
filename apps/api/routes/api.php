<?php

use App\Modules\Core\Http\OrgController;
use App\Modules\Core\Http\PartController;
use App\Modules\Core\Http\PartnerController;
use App\Modules\Core\Http\ResourceController;
use App\Modules\Planning\Http\BomController;
use App\Modules\Planning\Http\BomRoutingMapController;
use App\Modules\Planning\Http\RoutingController;
use App\Platform\Access\Http\AccessController;
use App\Platform\Access\Http\MemberController;
use App\Platform\Auth\Http\AuthController;
use App\Platform\Http\Controllers\HealthController;
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
    });

    // Query resources (ADR-0008, doc 24 §3): one read-only TenantTransaction; resources registered in config/eora.php
    Route::prefix('query')->name('query.')->middleware('tenant')->group(function (): void {
        Route::get('{resource}/schema', [QueryController::class, 'schema'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('schema');
        Route::get('{resource}', [QueryController::class, 'index'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('index');
    });
});
