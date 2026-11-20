<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Outbox\OutboxRelay;
use Tests\Support\AccessFixtures;

/* SPK per Line tests (doc 38, D-138 / D-139): a released WO, the weld line with 7 hours per shift, signed in as PPC. */

/** @return array<string, mixed> mfFixture (a released WO) + the weld line with 7 hours per shift, signed in as PPC */
function spkFixture(object $test, array $t): array
{
    $f = mfFixture($test, $t);
    $company = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f) {
        $db->update('update work_centers set available_hours_per_shift = 7 where id = ?', [$f['wc2']['id']]);

        return (string) $db->selectOne('select c.code from plants p join companies c on c.id = p.company_id where p.id = ?', [$f['plant']])->code;
    });
    $ppc = rtSwitch($test, $t, 'PPC');
    $op = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 20',
        [$f['wo']['id']],
    )->id);

    return $f + ['company' => $company, 'ppc' => $ppc, 'weldOp' => $op];
}

/** Runs the outbox relay until nothing is due: the release notice travels through the outbox (AGENTS.md). */
function spkDrainOutbox(): void
{
    for ($i = 0; $i < 50 && app(OutboxRelay::class)->runOnce('spk-test-relay', 500) > 0; $i++);
}

function spkLine(array $f, array $over = []): array
{
    return $over + ['workOrderId' => $f['wo']['id'], 'routingOperationId' => $f['weldOp'], 'machineId' => $f['m2']['id'], 'plannedQty' => '600', 'priority' => 1];
}
