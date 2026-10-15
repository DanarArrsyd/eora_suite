<?php

use App\Modules\Core\Http\OrgController;
use App\Modules\Core\Http\PartController;
use App\Modules\Core\Http\ResourceController;
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
    });

    // Query resources (ADR-0008, doc 24 §3): one read-only TenantTransaction; resources registered in config/eora.php
    Route::prefix('query')->name('query.')->middleware('tenant')->group(function (): void {
        Route::get('{resource}/schema', [QueryController::class, 'schema'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('schema');
        Route::get('{resource}', [QueryController::class, 'index'])->where('resource', '[a-z][a-z_]*\.[a-z][a-z_]*')->name('index');
    });
});
