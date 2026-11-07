<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 4 (doc 32 §1.4, doc 04 row 4, D-048, D-098): material transfer within a plant through the HTTP API — the same
 * item, lot and status move between locations of the same kind; anything else has its own document.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

it('moves part of a lot rack to rack in one step, keeping its status, and replays a retried request (D-048)', function () {
    orgUser($this, $this->t, 'MAT');
    $f = $this->f + ['lotId' => rcReceive($this, $this->f)->assertCreated()->json('data.lotId')];
    $year = substr($f['today'], 0, 4);

    $moved = trMove($this, $f)->assertCreated()->assertJsonPath('data.docNo', "TRF-P1-{$year}-0001")->json('data');
    expect(trStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '6', 'RM-02 BLOCKED' => '4']);
    trMove($this, $f)->assertOk()->assertJsonPath('data.documentId', $moved['documentId'])->assertJsonPath('data.replayed', true);
    trMove($this, $f, ['qty' => '5'])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    expect(trStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '6', 'RM-02 BLOCKED' => '4']);
})->group('integration', 'inventory');

it('moves only between active locations of the same kind in the plant, never more than is there (D-098)', function () {
    orgUser($this, $this->t, 'MAT');
    $f = $this->f + ['lotId' => rcReceive($this, $this->f)->assertCreated()->json('data.lotId')];
    $map = rcRun($this->t, function (EoraPostgresConnection $db) use ($f) {
        $wc = (string) $db->selectOne("insert into work_centers (tenant_id, plant_id, code, name, work_center_type) values ((select c.tenant from ctx_api.context() c), ?, 'PRS-01', 'Press 1', 'PRESS') returning id", [$f['plant']])->id;
        $db->update("update locations set status = 'INACTIVE' where id = ?", [$f['rm2']]);

        return (string) $db->selectOne('select id from locations where work_center_id = ?', [$wc])->id;
    });

    // into Material at Production is an issue (M4), not a transfer
    trMove($this, $f, ['toLocationId' => $map], 'transfer-key-0002')->assertStatus(422)->assertJsonValidationErrors(['toLocationId']);
    trMove($this, $f, [], 'transfer-key-0003')->assertStatus(422)->assertJsonPath('errors.toLocationId', ['Choose an active location of this plant.']);
    trMove($this, $f, ['toLocationId' => $f['other']], 'transfer-key-0004')->assertStatus(422)->assertJsonValidationErrors(['toLocationId']);
    trMove($this, $f, ['toLocationId' => $f['rm1']], 'transfer-key-0005')->assertStatus(422)->assertJsonValidationErrors(['toLocationId']);
    trMove($this, $f, ['fromLocationId' => $f['supplierLoc']], 'transfer-key-0006')->assertStatus(422)->assertJsonValidationErrors(['fromLocationId']);
    trMove($this, $f, ['qty' => '1.5'], 'transfer-key-0007')->assertStatus(422)->assertJsonPath('errors.qty', ['Enter a whole number of PCS.']);

    rcRun($this->t, fn (EoraPostgresConnection $db) => $db->update("update locations set status = 'ACTIVE' where id = ?", [$f['rm2']]));
    trMove($this, $f, ['qty' => '11'], 'transfer-key-0008')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    // the lot is BLOCKED: there is no UNRESTRICTED stock of it to move
    trMove($this, $f, ['stockStatus' => 'UNRESTRICTED'], 'transfer-key-0009')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    expect(trStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '10']);
})->group('integration', 'inventory');

it('posts transfers only with materials.transfer.post inside the plant scope (M3-D1)', function () {
    orgUser($this, $this->t, 'MAT');
    $f = $this->f + ['lotId' => rcReceive($this, $this->f)->assertCreated()->json('data.lotId')];
    rtSwitch($this, $this->t, 'QCI');
    trMove($this, $f)->assertForbidden();
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant2']]);
    trMove($this, $f)->assertForbidden();
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    trMove($this, $f)->assertCreated();
})->group('integration', 'inventory');
