<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\BalanceVerification;
use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 9 (doc 32 §1.9, BR-INV-11, D-093): the Movement Ledger list (newest first, stock side, filters, search, scope)
 * read through its indexes, and the nightly check that the balance projection equals the ledger.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

it('lists the ledger newest first with the stock side, filters and search, inside the plant scope', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $f)->assertCreated()->json('data');
    $transfer = trMove($this, $f + ['lotId' => $receipt['lotId']])->assertCreated()->json('data');
    rcReceive($this, $f, ['itemId' => $f['oil'], 'supplierLotNo' => null, 'qty' => '7'], 'receipt-key-0002')->assertCreated();

    $all = spa($this)->getJson('/api/v1/query/inventory.movements')->assertOk()->json('data');
    expect($all)->toHaveCount(6)->and($all[0]['itemCode'])->toBe('OIL-1')->and($all[5]['code'])->toBe($receipt['docNo']);
    // stock side: what entered or left a physical location, without the supplier counter-leg
    $stock = collect(spa($this)->getJson('/api/v1/query/inventory.movements?filter[stockSide]=true&filter[itemId]='.$f['coil'])->assertOk()->json('data'));
    expect($stock->map(fn ($m) => [$m['movementCode'], $m['locationCode'], $m['qty']])->all())->toBe([
        ['TRANSFER_LOC', 'RM-02', '4'], ['TRANSFER_LOC', 'RM-01', '-4'], ['RCPT_SUPPLIER', 'RM-01', '10'],
    ]);
    spa($this)->getJson('/api/v1/query/inventory.movements?filter[movementCode]=TRANSFER_LOC')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', $transfer['docNo'])->assertJsonPath('data.0.postedByName', fn ($n) => $n !== null);
    spa($this)->getJson('/api/v1/query/inventory.movements?q=oil')->assertOk()->assertJsonCount(2, 'data');
    spa($this)->getJson('/api/v1/query/inventory.movements?q='.strtolower(substr($receipt['docNo'], 0, 6)))->assertOk()->assertJsonCount(4, 'data');
    spa($this)->getJson('/api/v1/query/inventory.movements?sort=itemCode')->assertStatus(422);

    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant2']]);
    spa($this)->getJson('/api/v1/query/inventory.movements')->assertOk()->assertJsonPath('data', []);
})->group('integration', 'inventory');

it('reads a ledger page and an item filter through the ledger indexes under RLS (D-093)', function () {
    $f = $this->f;
    // 600 small receipts (1,200 legs), each its own posting
    $postings = app(InventoryPostingService::class);
    for ($i = 0; $i < 600; $i++) {
        rcRun($this->t, fn (EoraPostgresConnection $db) => $postings->post($db, new PostingCommand('RM_RECEIPT', $f['plant'], sprintf('ledger-bulk-%04d', $i), [
            new PostingLeg('RCPT_SUPPLIER', $f['supplierLoc'], $f['oil'], null, 'UNRESTRICTED', '-1'),
            new PostingLeg('RCPT_SUPPLIER', $f['rm1'], $f['oil'], null, 'UNRESTRICTED', '1'),
        ])));
    }
    orgUser($this, $this->t, 'MAT');
    rcReceive($this, $f)->assertCreated();
    foreach (['inventory_movements', 'inventory_documents', 'items', 'locations', 'plants'] as $table) {
        DB::connection('pgsql_owner')->statement("analyze {$table}");
    }

    $plans = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$plans) {
        if (str_starts_with($e->sql, 'with page') || str_starts_with($e->sql, 'select count(*)')) {
            $kind = str_starts_with($e->sql, 'with page') ? 'page' : 'count';
            $plans[$kind][] = implode("\n", array_column($e->connection->select('explain (analyze, costs off, timing off) '.$e->sql, $e->bindings), 'QUERY PLAN'));
        }
    });
    $page = spa($this)->getJson('/api/v1/query/inventory.movements?page[size]=20')->assertOk()->json();
    spa($this)->getJson('/api/v1/query/inventory.movements?filter[itemId]='.$f['coil'])->assertOk()->assertJsonCount(2, 'data');
    Event::forget(QueryExecuted::class);

    expect($page['meta']['total'])->toBe(1202)->and($page['data'][0]['itemCode'])->toBe('COIL-1');
    expect($plans['page'][0])->toContain('inventory_movements_ledger_idx')->not->toContain('Seq Scan on inventory_movements');
    expect($plans['page'][1].$plans['count'][1])->toContain('inventory_movements_item_idx')->not->toContain('Seq Scan on inventory_movements');
})->group('integration', 'inventory');

it('finds the balances equal to the ledger after receipts, moves, splits, adjustments and reversals (BR-INV-11)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $f)->assertCreated()->json('data');
    trMove($this, $f + ['lotId' => $receipt['lotId']])->assertCreated();
    rcReceive($this, $f, ['itemId' => $f['oil'], 'supplierLotNo' => null, 'qty' => '7'], 'receipt-key-0002')->assertCreated();
    rtSwitch($this, $this->t, 'PRL');
    $split = lsSplit($this, $f + ['lotId' => $receipt['lotId']], ['children' => [['qty' => '2']]])->assertCreated()->json('data.documentId');
    $undo = rvRequest($this, $split)->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    rvApprove($this, $undo)->assertCreated();
    $adjust = adRequest($this, $f + ['lotId' => $receipt['lotId']], ['qty' => '1'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    adApprove($this, $adjust)->assertCreated();

    expect(app(BalanceVerification::class)->verify($this->t['tenant']))->toBe([]);
    $this->artisan('eora:inventory:verify-balances', ['--tenant' => $this->t['tenant']])->assertSuccessful();
})->group('integration', 'inventory');

it('reports a balance that differs from the ledger: audited, published, and the command fails (BR-INV-11)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    $lot = rcReceive($this, $f)->assertCreated()->json('data.lotId');
    // a projection row the ledger does not explain (only possible past the database's own checks: as the owner, with
    // the balance triggers off for this statement)
    $owner = DB::connection('pgsql_owner');
    $owner->transaction(function () use ($owner, $lot) {
        $owner->statement('alter table stock_balances disable trigger user');
        $owner->statement('alter table stock_balances no force row level security');
        expect($owner->update('update stock_balances set qty = qty + 3 where lot_id = ?', [$lot]))->toBe(1);
        $owner->statement('alter table stock_balances force row level security');
        $owner->statement('alter table stock_balances enable trigger user');
    });

    $differences = app(BalanceVerification::class)->verify($this->t['tenant']);
    expect($differences)->toHaveCount(1)->and($differences[0])->toMatchArray(['lotId' => $lot, 'stockStatus' => 'BLOCKED', 'ledger' => '10', 'balance' => '13']);
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne("select count(*) as n from audit_events where action = 'inventory.projection_mismatch'")->n))->toBe(1);
    $this->artisan('eora:inventory:verify-balances', ['--tenant' => $this->t['tenant']])->assertFailed();
    $this->artisan('eora:inventory:verify-balances', ['--tenant' => 'not-a-uuid'])->assertExitCode(2);

    $owner->transaction(function () use ($owner, $lot) {
        $owner->statement('alter table stock_balances disable trigger user');
        $owner->statement('alter table stock_balances no force row level security');
        $owner->update('update stock_balances set qty = qty - 3 where lot_id = ?', [$lot]);
        $owner->statement('alter table stock_balances force row level security');
        $owner->statement('alter table stock_balances enable trigger user');
    });
    expect(app(BalanceVerification::class)->verify($this->t['tenant']))->toBe([]);
})->group('integration', 'inventory');
