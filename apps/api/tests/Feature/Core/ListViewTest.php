<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * Saved list views (EORA-COMPONENT-STANDARDS §4, UI/UX audit U-13): personal, per list, replace by name, private to the
 * user in the active tenant.
 */

it('saves, replaces, lists and deletes a user\'s own views of one list', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $user = member($this, $t);
    signIn($this, $user, $t);
    $state = ['search' => 'BRK', 'filters' => ['owner' => 'COMPANY', 'stockStatus' => ['UNRESTRICTED']], 'sort' => [['id' => 'itemCode', 'desc' => false]], 'columns' => ['lotNo' => false]];

    $first = spa($this)->postJson('/api/v1/list-views', ['list' => 'stock-overview', 'name' => 'Company stock', 'state' => $state])
        ->assertCreated()->assertJsonPath('data.name', 'Company stock')->assertJsonPath('data.state.filters.owner', 'COMPANY')->json('data');
    // the same name replaces the settings instead of making a second view
    $state['search'] = 'NUT';
    spa($this)->postJson('/api/v1/list-views', ['list' => 'stock-overview', 'name' => 'Company stock', 'state' => $state])
        ->assertCreated()->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.state.search', 'NUT');
    spa($this)->postJson('/api/v1/list-views', ['list' => 'material-lots', 'name' => 'Other list', 'state' => ['search' => '']])->assertCreated();

    spa($this)->getJson('/api/v1/list-views?list=stock-overview')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first['id']);

    // only settings are kept: unknown state keys and malformed lists are refused
    spa($this)->postJson('/api/v1/list-views', ['list' => 'stock-overview', 'name' => 'x', 'state' => ['rows' => [1]]])->assertStatus(422);
    spa($this)->postJson('/api/v1/list-views', ['list' => 'Stock Overview', 'name' => 'x', 'state' => []])->assertStatus(422);
    spa($this)->postJson('/api/v1/list-views', ['list' => 'stock-overview', 'name' => '   ', 'state' => ['search' => '']])->assertStatus(422);

    spa($this)->deleteJson("/api/v1/list-views/{$first['id']}")->assertNoContent();
    spa($this)->deleteJson("/api/v1/list-views/{$first['id']}")->assertNotFound();
    spa($this)->getJson('/api/v1/list-views?list=stock-overview')->assertOk()->assertJsonPath('data', []);
})->group('integration', 'core');

it('keeps views private to their user and tenant', function () {
    $t = TenantFixtures::provision();
    $other = TenantFixtures::provision();
    AccessFixtures::roles($t);
    AccessFixtures::roles($other);
    $owner = member($this, $t);
    $colleague = member($this, $t);
    signIn($this, $owner, $t);
    $view = spa($this)->postJson('/api/v1/list-views', ['list' => 'daily-recovery', 'name' => 'Plan needed', 'state' => ['filters' => ['status' => ['PLAN_REQUIRED']]]])
        ->assertCreated()->json('data');

    signIn($this, $colleague, $t);
    spa($this)->getJson('/api/v1/list-views?list=daily-recovery')->assertOk()->assertJsonPath('data', []);
    spa($this)->deleteJson("/api/v1/list-views/{$view['id']}")->assertNotFound();
    // direct SQL as the runtime role cannot read, rename or write another user's view either
    expect(asApp($t, $colleague['id'], fn (EoraPostgresConnection $db) => $db->update("update list_views set name = 'Taken' where id = ?", [$view['id']])))->toBe(0)
        ->and(fn () => asApp($t, $colleague['id'], fn (EoraPostgresConnection $db) => $db->insert(
            "insert into list_views (tenant_id, user_id, list_key, name, state) values (?, ?, 'daily-recovery', 'Planted', '{}')",
            [$t['tenant'], $owner['id']],
        )))->toThrow(QueryException::class);
    expect(AccessFixtures::run($other, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from list_views')->n))->toBe(0);

    signIn($this, $owner, $t);
    spa($this)->getJson('/api/v1/list-views?list=daily-recovery')->assertOk()->assertJsonPath('data.0.name', 'Plan needed');
})->group('integration', 'core');
