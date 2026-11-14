<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 7a (doc 33 §1.7, D-115, doc 04 rows 2 and 11, BR-FG-02, BR-DEM-09/10, doc 14 #25, #33): delivery allocates FG
 * to a firm schedule line from a FIFO proposal, the FG warehouse picks it to staging (part or all), and staged goods go
 * back to an FG rack by an unpick.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

it('proposes FIFO, allocates, picks part of an allocation, keeps the rest allocated and puts staged goods back (D-115)', function () {
    $f = dlFixture($this, $this->t);
    [$a, $b] = $f['lots'];
    $fg1 = ($f['line'])($a, 'UNRESTRICTED');
    $fg2 = ($f['line'])($b, 'UNRESTRICTED');

    // FIFO: the older lot first, then the next, up to what the line needs
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}/proposal")->assertOk()->assertJsonPath('data.toAllocate', '100')
        ->assertJsonPath('data.lines.0.stockLineId', $fg1)->assertJsonPath('data.lines.0.proposedQty', '90')
        ->assertJsonPath('data.lines.1.stockLineId', $fg2)->assertJsonPath('data.lines.1.proposedQty', '10');

    $lines = [['stockLineId' => $fg1, 'qty' => '90'], ['stockLineId' => $fg2, 'qty' => '10']];
    $alloc = dlAllocate($this, $f, $lines)->assertCreated()->json('data.allocationIds');
    dlAllocate($this, $f, $lines)->assertOk()->assertJsonPath('data.allocationIds', $alloc)->assertJsonPath('data.replayed', true);
    dlAllocate($this, $f, [['stockLineId' => $fg2, 'qty' => '1']], 'dl-alloc-0002')->assertStatus(409)->assertJsonPath('code', 'OVER_ALLOCATION');
    spa($this)->getJson('/api/v1/query/inventory.stock?filter[id]='.$fg2)->assertOk()->assertJsonPath('data.0.qty', '90')->assertJsonPath('data.0.availableQty', '80');
    // a schedule line with FG allocated is not cancelled (D-108 (3))
    signIn($this, $f['ppc'], $this->t);
    $so = spa($this)->getJson("/api/v1/demand/sales-orders/{$f['so']['id']}")->assertOk()->json('data');
    spa($this)->json('POST', "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$f['schedule']}/actions/cancel", ['reason' => 'Customer cancelled'], ['If-Match' => '"'.$so['version'].'"'])
        ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_LINE_ALLOCATED');
    signIn($this, $f['dlv'], $this->t);
    // allocated FG cannot be moved away by anything else (BR-FG-02)
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}")->assertOk()->assertJsonPath('data.allocatedQty', '100')->assertJsonPath('data.toAllocateQty', '0')
        ->assertJsonCount(2, 'data.allocations');

    // the FG warehouse picks 60 of the first allocation and all of the second; 30 stays allocated
    signIn($this, $f['fgw'], $this->t);
    $pick = fn (array $lines, string $key) => spa($this)->postJson('/api/v1/delivery/picks', ['plantId' => $f['plant'], 'lines' => $lines], ['Idempotency-Key' => $key]);
    $pick([['allocationId' => $alloc[0], 'qty' => '91']], 'dl-pick-0009')->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    $posted = $pick([['allocationId' => $alloc[0], 'qty' => '60'], ['allocationId' => $alloc[1], 'qty' => '10']], 'dl-pick-0001')->assertCreated()
        ->assertJsonPath('data.docNo', fn (string $no) => str_starts_with($no, 'PCK-P1-'))->json('data');
    $pick([['allocationId' => $alloc[0], 'qty' => '60'], ['allocationId' => $alloc[1], 'qty' => '10']], 'dl-pick-0001')->assertOk()->assertJsonPath('data.documentId', $posted['documentId']);
    expect(fgStock($this->t, $f['item']['id']))->toMatchArray(['FG-01 / UNRESTRICTED' => '30', 'FG-02 / UNRESTRICTED' => '80', 'STG-01 / UNRESTRICTED' => '70']);
    $line = spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}")->assertOk()
        ->assertJsonPath('data.allocatedQty', '30')->assertJsonPath('data.stagedQty', '70')->assertJsonPath('data.toAllocateQty', '0')->json('data');
    $pick([['allocationId' => $alloc[0], 'qty' => '1']], 'dl-pick-0008')->assertStatus(422)->assertJsonValidationErrors(['lines.0.allocationId']);
    $rest = $line['allocations'][0]['id'];

    // staged goods of the line go back to an FG rack, unallocated; never more than was picked for it
    $staged = collect($line['staged'])->firstWhere('qty', '60')['stockLineId'];
    $unpick = fn (string $qty, string $key) => spa($this)->postJson('/api/v1/delivery/unpicks', ['plantId' => $f['plant'], 'locationId' => $f['fg1'],
        'lines' => [['scheduleLineId' => $f['schedule'], 'stockLineId' => $staged, 'qty' => $qty]]], ['Idempotency-Key' => $key]);
    $unpick('61', 'dl-unpick-0009')->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    $unpicked = $unpick('20', 'dl-unpick-0001')->assertCreated()->json('data.documentId');
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}")->assertOk()->assertJsonPath('data.stagedQty', '50')->assertJsonPath('data.toAllocateQty', '20');

    // delivery releases what stays allocated, with the reason; the line can be allocated again
    signIn($this, $f['dlv'], $this->t);
    spa($this)->postJson("/api/v1/delivery/allocations/{$rest}/release", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    spa($this)->postJson("/api/v1/delivery/allocations/{$rest}/release", ['reason' => 'Customer moved the date'])->assertNoContent();
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}")->assertOk()->assertJsonPath('data.allocatedQty', '0')->assertJsonPath('data.toAllocateQty', '50');
    spa($this)->getJson('/api/v1/query/delivery.schedule_lines')->assertOk()->assertJsonPath('data.0.stagedQty', '50')->assertJsonPath('data.0.toAllocateQty', '50');

    // the line is fully committed again; reversing the unpick would stage 20 more than it requires: refused
    dlAllocate($this, $f, [['stockLineId' => $fg2, 'qty' => '50']], 'dl-alloc-0003')->assertCreated();
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV');
    signIn($this, $f['fgw'], $this->t);
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $unpicked, 'reason' => 'Put back by mistake'], ['Idempotency-Key' => 'dl-reverse-0001'])
        ->assertCreated()->json('data.documentId');
    AccessFixtures::grant($this->t, $f['ppc']['id'], 'CSA');
    signIn($this, $f['ppc'], $this->t);
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'dl-reverse-0002'])
        ->assertStatus(422)->assertJsonPath('code', 'POSTING_REFUSED');
    signIn($this, $f['dlv'], $this->t);
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$f['schedule']}")->assertOk()->assertJsonPath('data.stagedQty', '50')->assertJsonPath('data.allocatedQty', '50');
    spa($this)->getJson('/api/v1/query/delivery.picks?sort=postedAt')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.direction', 'PICK')->assertJsonPath('data.1.direction', 'UNPICK');

    // a schedule line with staged goods is not cancelled
    signIn($this, $f['ppc'], $this->t);
    $so = spa($this)->getJson("/api/v1/demand/sales-orders/{$f['so']['id']}")->assertOk()->json('data');
    spa($this)->json('POST', "/api/v1/demand/sales-orders/{$so['id']}/schedule-lines/{$f['schedule']}/actions/cancel", ['reason' => 'Customer cancelled'], ['If-Match' => '"'.$so['version'].'"'])
        ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_LINE_ALLOCATED');
})->group('integration', 'delivery');

it('refuses allocations and picks outside the rules, in the database too (D-115, BR-FG-02)', function () {
    $f = dlFixture($this, $this->t);
    [$a] = $f['lots'];
    $fg1 = ($f['line'])($a, 'UNRESTRICTED');
    dlAllocate($this, $f, [['stockLineId' => $fg1, 'qty' => '91']], 'dl-alloc-0009')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE');
    dlAllocate($this, $f, [['stockLineId' => ($f['line'])($a, 'REWORK'), 'qty' => '1']], 'dl-alloc-0008')->assertStatus(422)->assertJsonValidationErrors(['lines.0.stockLineId']);
    $alloc = dlAllocate($this, $f, [['stockLineId' => $fg1, 'qty' => '50']])->assertCreated()->json('data.allocationIds.0');

    // only delivery allocates; the FG warehouse picks but does not allocate or release
    signIn($this, $f['fgw'], $this->t);
    dlAllocate($this, $f, [['stockLineId' => $fg1, 'qty' => '1']], 'dl-alloc-0007')->assertForbidden();
    spa($this)->postJson("/api/v1/delivery/allocations/{$alloc}/release", ['reason' => 'Not mine'])->assertForbidden();

    // allocated stock is held: a transfer of it is refused (available = on-hand − allocated)
    $refused = function (Closure $attempt, string $why) {
        try {
            $attempt();
            $this->fail("passed: {$why}");
        } catch (DomainProblem $e) {
            expect($e->errorCode)->toBeIn(['POSTING_REFUSED', 'INVALID_LEGS', 'INSUFFICIENT_AVAILABLE'], $why);
        } catch (QueryException|PDOException $e) {
            expect((string) $e->getCode())->toBeIn(['23514', '42501'], $why);
        }
    };
    $fgw = (string) $f['fgw']['id'];
    $post = fn (array $legs, string $key) => asApp($this->t, $fgw, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db,
        new PostingCommand('PICK', $f['plant'], $key, $legs)));
    // a pick without consuming its allocation, and into the NG area
    $refused(fn () => $post([
        new PostingLeg('PICK', $f['fg1'], $f['item']['id'], $a, 'UNRESTRICTED', '-5', scheduleLineId: $f['schedule']),
        new PostingLeg('PICK', $f['staging'], $f['item']['id'], $a, 'UNRESTRICTED', '5', scheduleLineId: $f['schedule']),
    ], 'dl-direct-0001'), 'picked without an allocation');
    $refused(fn () => $post([
        new PostingLeg('PICK', $f['fg1'], $f['item']['id'], $a, 'UNRESTRICTED', '-5', scheduleLineId: $f['schedule']),
        new PostingLeg('PICK', $f['ng'], $f['item']['id'], $a, 'UNRESTRICTED', '5', scheduleLineId: $f['schedule']),
    ], 'dl-direct-0002'), 'picked into the NG area');
    // nothing else moves stock to staging
    $refused(fn () => asApp($this->t, $fgw, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db, new PostingCommand('MAT_TRANSFER', $f['plant'], 'dl-direct-0003', [
        new PostingLeg('TRANSFER_LOC', $f['fg1'], $f['item']['id'], $a, 'UNRESTRICTED', '-5'),
        new PostingLeg('TRANSFER_LOC', $f['staging'], $f['item']['id'], $a, 'UNRESTRICTED', '5'),
    ]))), 'transferred to staging');
    // an allocation straight in the table beyond what the line requires
    $refused(fn () => asApp($this->t, (string) $f['dlv']['id'], fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_allocations (tenant_id, balance_id, demand_type, demand_id, qty) values ((select c.tenant from ctx_api.context() c), ?, 'SCHEDULE_LINE', ?, 60)",
        [$fg1, $f['schedule']],
    )), 'allocated beyond the requirement');
    expect(fgStock($this->t, $f['item']['id']))->not->toHaveKey('STG-01 / UNRESTRICTED');
})->group('integration', 'delivery');
