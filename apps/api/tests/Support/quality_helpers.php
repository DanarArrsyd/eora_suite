<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;

/* Quality tests (M4 step 5) and the approval notice tests (D-129): a produced lot waiting for QC. */

/**
 * prFixture with an item that needs inspection; the production leader posts 90 good (PENDING_QC), 5 rework, 3 reject.
 * Ends signed in as an inspector (QCI).
 *
 * @return array<string, mixed>
 */
function qcFixture(object $test, array $t): array
{
    $f = prFixture($test, $t);
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update items set inspection_required = true where id = ?', [$f['item']['id']]));
    $run = prOpen($test, $f)->assertCreated()->json('data');
    $posted = prPost($test, $f, $run['id'])->assertCreated()->json('data');
    $line = fn (string $type, string $status) => (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select b.id from stock_balances b join locations l on l.id = b.location_id where b.item_id = ? and l.location_type = ? and b.stock_status = ?',
        [$f['item']['id'], $type, $status],
    )->id);
    $qci = rtSwitch($test, $t, 'QCI');

    return $f + ['run' => $run, 'posted' => $posted, 'pending' => $line('QC_AREA', 'PENDING_QC'), 'line' => $line, 'qci' => $qci];
}

function qcDispose(object $test, string $line, array $lines, string $key = 'qc-dispose-0001')
{
    return spa($test)->postJson('/api/v1/quality/dispositions', ['stockLineId' => $line, 'lines' => $lines], ['Idempotency-Key' => $key]);
}
