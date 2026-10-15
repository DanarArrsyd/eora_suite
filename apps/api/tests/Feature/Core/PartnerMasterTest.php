<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 2b (doc 29 §1.2, doc 26 §2.2, D-034, D-073): customers, suppliers and the customer part mapping through the
 * HTTP API (group B), with the database rules behind them.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
});

function partnerItem(object $test, array $t, string $code): array
{
    $pcs = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'PCS'")->id);

    return spa($test)->postJson('/api/v1/core/items', ['code' => $code, 'name' => "Part {$code}", 'itemType' => 'FINISHED', 'baseUomId' => $pcs])->assertCreated()->json('data');
}

it('keeps customers and suppliers as separate masters with fixed codes', function () {
    orgUser($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][0]]); // D-073: any scope
    $c = spa($this)->postJson('/api/v1/core/customers', ['code' => 'ASTRA', 'name' => 'PT Astra (sample)'])->assertCreated()->json('data');
    spa($this)->postJson('/api/v1/core/suppliers', ['code' => 'ASTRA', 'name' => 'Same code, other master'])->assertCreated();
    spa($this)->postJson('/api/v1/core/customers', ['code' => 'ASTRA', 'name' => 'Again'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A customer with this code already exists.');
    spa($this)->postJson('/api/v1/core/customers', ['code' => 'astra-2', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['code']);

    patchWith($this, '/api/v1/core/customers/'.$c['id'], ['status' => 'INACTIVE'], 1)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
    spa($this)->getJson('/api/v1/query/core.customers?q=astra')->assertJsonPath('meta.total', 1);
    spa($this)->getJson('/api/v1/query/core.suppliers?filter[status]=ACTIVE')->assertJsonPath('meta.total', 1);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update customers set code = 'OTHER' where id = ?", [$c['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('maps one customer part number to exactly one item on every day; a new mapping closes the open one', function () {
    orgUser($this, $this->t, 'CSA');
    $customer = spa($this)->postJson('/api/v1/core/customers', ['code' => 'C1', 'name' => 'Customer 1'])->json('data');
    $a = partnerItem($this, $this->t, 'BRK-A');
    $b = partnerItem($this, $this->t, 'BRK-B');
    $today = Date::now('Asia/Jakarta')->toDateString();
    $map = fn (array $item, string $day, string $partNo = '52100-K0J') => spa($this)->postJson('/api/v1/core/customer-items', [
        'customerId' => $customer['id'], 'itemId' => $item['id'], 'customerPartNo' => $partNo, 'customerDrawingRev' => 'C', 'effectiveFrom' => $day,
    ]);

    $first = $map($a, '2026-01-01')->assertCreated()->assertJsonPath('data.itemCode', 'BRK-A')->assertJsonPath('data.current', true)->json('data');
    // same part number, same customer, overlapping: refused
    $map($b, '2026-01-01')->assertStatus(422)->assertJsonPath('errors.effectiveFrom.0', 'A mapping of this customer part already starts on or after this day.');
    // engineering change: from a later day the part number means item B; mapping A ends the day before
    $map($b, '2026-06-01')->assertCreated();
    $rows = spa($this)->getJson('/api/v1/query/core.customer_items?filter[customerId]='.$customer['id'])->assertJsonPath('meta.total', 2)->json('data');
    $closed = collect($rows)->firstWhere('id', $first['id']);
    expect($closed['effectiveTo'])->toBe('2026-05-31')->and(collect($rows)->firstWhere('itemCode', 'BRK-B')['effectiveTo'])->toBeNull();

    // another customer part number for the same item is fine (an item may serve many customers and numbers)
    $map($a, $today, '52100-K0J-ALT')->assertCreated();

    // the database refuses an overlapping second mapping, and a closed mapping never moves
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, effective_from) values (?, ?, ?, '52100-K0J', '2026-03-01')",
        [$this->t['tenant'], $customer['id'], $b['id']],
    )))->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update customer_items set effective_to = '2026-12-31' where id = ?", [$first['id']],
    )))->toThrow(QueryException::class);

    patchWith($this, '/api/v1/core/customer-items/'.$first['id'], ['customerDrawingRev' => 'D'], $closed['version'])->assertOk()->assertJsonPath('data.customerDrawingRev', 'D');
})->group('integration', 'master');

it('refuses mappings to inactive customers or items and hides other tenants', function () {
    orgUser($this, $this->t, 'CSA');
    $customer = spa($this)->postJson('/api/v1/core/customers', ['code' => 'C2', 'name' => 'Customer 2'])->json('data');
    $item = partnerItem($this, $this->t, 'BRK-C');
    patchWith($this, '/api/v1/core/items/'.$item['id'], ['status' => 'OBSOLETE'], 1)->assertOk();
    spa($this)->postJson('/api/v1/core/customer-items', ['customerId' => $customer['id'], 'itemId' => $item['id'], 'customerPartNo' => 'X', 'effectiveFrom' => '2026-01-01'])
        ->assertStatus(422)->assertJsonPath('errors.itemId.0', 'Only active items can be mapped.');

    $other = TenantFixtures::provision(1);
    AccessFixtures::roles($other);
    $foreign = (string) AccessFixtures::run($other, fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into customers (tenant_id, code, name) values (?, 'F1', 'Foreign') returning id", [$other['tenant']],
    )->id);
    spa($this)->postJson('/api/v1/core/customer-items', ['customerId' => $foreign, 'itemId' => $item['id'], 'customerPartNo' => 'X', 'effectiveFrom' => '2026-01-01'])
        ->assertStatus(422)->assertJsonPath('errors.customerId.0', 'Unknown customer.');
    patchWith($this, '/api/v1/core/customers/'.$foreign, ['name' => 'x'], 1)->assertNotFound();
})->group('integration', 'master');
