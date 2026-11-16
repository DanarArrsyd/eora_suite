<?php

declare(strict_types=1);

use App\Platform\Security\SecurityHasher;
use Illuminate\Support\Facades\RateLimiter;

/* MPS / MRP tests (M5 steps 3–4): an approved-able demand picture and the MPS lifecycle through the API. */

function mpsAs(object $test, array $t, array $u): void
{
    spa($test)->postJson('/api/v1/auth/logout')->assertNoContent();
    signIn($test, $u, $t);
}

function mpsAct(object $test, array $m, string $action, array $body = [])
{
    return spa($test)->postJson("/api/v1/planning/mps-versions/{$m['id']}/actions/{$action}", $body, ['If-Match' => '"'.$m['version'].'"']);
}

/**
 * dlFixture (180 PCS of BRK-100 in the FG warehouse, a firm line of 100 three days ahead) plus an active forecast of the
 * customer: 150 in that week (100 consumed by the firm line, 50 net) and 300 two weeks later. Signed in as the planner.
 *
 * @return array<string, mixed>
 */
function mpsFixture(object $test, array $t): array
{
    $f = dlFixture($test, $t);
    mpsAs($test, $t, $f['ppc']);
    $weekA = date('Y-m-d', strtotime('monday this week', strtotime($f['today'].' +3 days')));
    $weekB = date('Y-m-d', strtotime($weekA.' +14 days'));
    $fc = spa($test)->postJson('/api/v1/demand/forecast-versions', ['plantId' => $f['plant'], 'customerId' => $f['customer']])->assertCreated()->json('data');
    $fc = spa($test)->putJson("/api/v1/demand/forecast-versions/{$fc['id']}/lines", ['lines' => [
        ['customerItemId' => $f['part'], 'weekStart' => $weekA, 'qty' => '150'],
        ['customerItemId' => $f['part'], 'weekStart' => $weekB, 'qty' => '300'],
    ]], ['If-Match' => '"'.$fc['version'].'"'])->assertOk()->json('data');
    spa($test)->postJson("/api/v1/demand/forecast-versions/{$fc['id']}/actions/activate", [], ['If-Match' => '"'.$fc['version'].'"'])->assertOk();
    // the fixture signed in nine new users; the tests below add their own within the invitation limit
    RateLimiter::clear(md5('invitation-accept'.'invite:ip:'.app(SecurityHasher::class)->hash('127.0.0.1')));

    return $f + ['weekA' => $weekA, 'weekB' => $weekB];
}
