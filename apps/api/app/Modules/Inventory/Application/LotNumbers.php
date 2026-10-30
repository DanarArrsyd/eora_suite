<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Database\EoraPostgresConnection;

/**
 * Internal lot numbers (M3-D3): `L<YYMM>-<0001>` per tenant, item and month of the day the lot belongs to. The counter
 * row is locked by its upsert until the caller's transaction ends, so two receipts of one item never share a number; a
 * rolled-back receipt gives its number back.
 */
final class LotNumbers
{
    public function next(EoraPostgresConnection $db, string $itemId, string $day): string
    {
        $period = substr($day, 2, 2).substr($day, 5, 2);
        $number = (int) $db->selectOne(
            'insert into lot_counters (tenant_id, item_id, period, last_no) values ((select c.tenant from ctx_api.context() c), ?, ?, 1)
             on conflict (tenant_id, item_id, period) do update set last_no = lot_counters.last_no + 1 returning last_no',
            [$itemId, $period],
        )->last_no;

        return sprintf('L%s-%04d', $period, $number);
    }
}
