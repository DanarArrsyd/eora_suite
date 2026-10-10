<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Group B/C bootstrap check (doc 25 §11): the application's default connection is the real runtime role.
it('serves the application through the non-superuser runtime role without BYPASSRLS', function () {
    $row = DB::selectOne('select current_user as u, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');

    expect($row->u)->toBe('eora_app')
        ->and($row->rolsuper)->toBeFalse()
        ->and($row->rolbypassrls)->toBeFalse();
})->group('integration');

it('cannot create objects, read the migrations table or reach definer schemas as the runtime role', function (string $sql) {
    DB::statement($sql);
})->with([
    'create table in public' => 'create table public.evil (id int)',
    'read migrations' => 'select count(*) from migrations',
    'use auth schema' => 'select count(*) from auth.no_such_table',
    'use ctx schema' => 'select count(*) from ctx.no_such_table',
    'create schema' => 'create schema evil',
])->throws(Illuminate\Database\QueryException::class, 'permission denied')->group('integration');
