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
 * M4 step 6 (doc 33 §1.6, D-114, doc 04 row 10, BR-FG-01, doc 14 #7): the FG warehouse receives QC-released finished
 * goods into an FG location as UNRESTRICTED — part or all of each lot, several lots in one document.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

it('receives part of one lot and all of another into the FG rack chosen; the rest stays QC released (BR-FG-01, doc 14 #7)', function () {
    $f = fgFixture($this, $this->t);
    [$a, $b] = $f['lots'];
    expect(fgStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '6', 'NG-01 / REWORK' => '10', 'QC-01 / QC_RELEASED' => '180']);

    $lines = [['stockLineId' => ($f['line'])($a), 'qty' => '60'], ['stockLineId' => ($f['line'])($b), 'qty' => '90']];
    $posted = fgReceive($this, $f, $lines, ['locationId' => $f['fg2'], 'note' => 'Shift 1'])->assertCreated()
        ->assertJsonPath('data.docNo', fn (string $no) => str_starts_with($no, 'FGR-P1-'))->json('data');
    fgReceive($this, $f, $lines, ['locationId' => $f['fg2'], 'note' => 'Shift 1'])->assertOk()->assertJsonPath('data.documentId', $posted['documentId']);
    expect(fgStock($this->t, $f['item']['id']))->toBe(['FG-02 / UNRESTRICTED' => '150', 'NG-01 / REJECTED' => '6', 'NG-01 / REWORK' => '10', 'QC-01 / QC_RELEASED' => '30']);

    // the rest later, into the plant's first FG location when none is chosen
    fgReceive($this, $f, [['stockLineId' => ($f['line'])($a), 'qty' => '30']], [], 'fg-receipt-0002')->assertCreated();
    expect(fgStock($this->t, $f['item']['id']))->toMatchArray(['FG-01 / UNRESTRICTED' => '30', 'FG-02 / UNRESTRICTED' => '150'])
        ->not->toHaveKey('QC-01 / QC_RELEASED');
    spa($this)->getJson('/api/v1/query/warehouse.fg_receipts?sort=locationCode')->assertStatus(422);
    spa($this)->getJson('/api/v1/query/warehouse.fg_receipts?sort=postedAt')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.locationCode', 'FG-02')->assertJsonPath('data.0.lines', 2)->assertJsonPath('data.0.note', 'Shift 1')
        ->assertJsonPath('data.1.locationCode', 'FG-01');

    // a wrong receipt is undone through the M3 reversal flow (asked, then approved by a second person)
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $posted['documentId'], 'reason' => 'Counted the wrong pallet'],
        ['Idempotency-Key' => 'fg-reverse-0001'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'fg-reverse-0002'])->assertCreated();
    expect(fgStock($this->t, $f['item']['id']))->toMatchArray(['FG-01 / UNRESTRICTED' => '30', 'QC-01 / QC_RELEASED' => '150'])
        ->not->toHaveKey('FG-02 / UNRESTRICTED');
})->group('integration', 'warehouse');

it('refuses what is not QC-released finished goods, more than released, and anyone but the FG warehouse (in the database too)', function () {
    $f = fgFixture($this, $this->t);
    [$a] = $f['lots'];
    $released = ($f['line'])($a);
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '91']], [], 'fg-receipt-0009')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '0']], [], 'fg-receipt-0008')->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '1.5']], [], 'fg-receipt-0007')->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '1'], ['stockLineId' => $released, 'qty' => '2']], [], 'fg-receipt-0006')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.stockLineId']);
    fgReceive($this, $f, [['stockLineId' => ($f['line'])($a, 'REWORK'), 'qty' => '1']], [], 'fg-receipt-0005')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.stockLineId']);
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '1']], ['locationId' => $f['qc']], 'fg-receipt-0004')
        ->assertStatus(422)->assertJsonValidationErrors(['locationId']);

    rtSwitch($this, $this->t, 'PRL');
    fgReceive($this, $f, [['stockLineId' => $released, 'qty' => '1']], [], 'fg-receipt-0003')->assertForbidden();

    $post = fn (string $actor, array $legs, string $key) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db,
        new PostingCommand('FG_RECEIPT', $f['plant'], $key, $legs)));
    $refused = function (Closure $attempt, string $why) {
        try {
            $attempt();
            $this->fail("posted: {$why}");
        } catch (DomainProblem $e) {
            expect($e->errorCode)->toBeIn(['POSTING_REFUSED', 'INVALID_LEGS'], $why);
        } catch (QueryException $e) {
            expect($e->getCode())->toBeIn(['23514', '42501'], $why);
        }
    };
    $receive = fn (string $to) => [
        new PostingLeg('FG_RECEIPT', $f['qc'], $f['item']['id'], $a, 'QC_RELEASED', '-1'),
        new PostingLeg('FG_RECEIPT', $to, $f['item']['id'], $a, 'UNRESTRICTED', '1'),
    ];
    $refused(fn () => $post((string) $f['prl']['id'], $receive($f['fg1']), 'fg-direct-0001'), 'received by the production leader');
    $refused(fn () => $post((string) $f['fgw']['id'], $receive($f['ng']), 'fg-direct-0002'), 'received into the NG area');
    $refused(fn () => $post((string) $f['fgw']['id'], [
        new PostingLeg('FG_RECEIPT', $f['qc'], $f['item']['id'], $a, 'QC_RELEASED', '-2'),
        new PostingLeg('FG_RECEIPT', $f['fg1'], $f['item']['id'], $a, 'UNRESTRICTED', '1'),
        new PostingLeg('FG_RECEIPT', $f['fg2'], $f['item']['id'], $a, 'UNRESTRICTED', '1'),
    ], 'fg-direct-0003'), 'received into two FG locations');
    expect(fgStock($this->t, $f['item']['id'])['QC-01 / QC_RELEASED'])->toBe('180');
})->group('integration', 'warehouse');
