<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;

/**
 * BR-INV-11 (ADR-0002): the balance projection equals the sum of the ledger. For one tenant, as the system actor, the
 * projection is rebuilt from the legs at physical locations and compared with `stock_balances` in one statement (one snapshot); every
 * key that differs is reported, audited and published. Nothing is repaired here: a difference is investigated (the
 * database refuses a posting whose balance and legs disagree, so one is never expected).
 */
final class BalanceVerification
{
    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly AuditRecorder $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** @return list<array{plantId: string, locationId: string, itemId: string, lotId: ?string, stockStatus: string, ledger: string, balance: string}> */
    public function verify(string $tenant): array
    {
        return $this->transaction->run(TenantContext::system($tenant), function (EoraPostgresConnection $db): array {
            // one statement, so one snapshot for both sides: a posting committing meanwhile is in both or in neither
            $rows = $db->select(
                "with ledger as (
                   select m.plant_id, m.location_id, m.item_id, m.lot_id, m.stock_status, m.ownership, m.uom_id, sum(m.qty_signed) as qty
                     from inventory_movements m
                     join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id and l.location_type not like 'V\\_%'
                    group by 1, 2, 3, 4, 5, 6, 7)
                 select coalesce(g.plant_id, b.plant_id) as plant_id, coalesce(g.location_id, b.location_id) as location_id,
                        coalesce(g.item_id, b.item_id) as item_id, coalesce(g.lot_id, b.lot_id) as lot_id,
                        coalesce(g.stock_status, b.stock_status) as stock_status,
                        trim_scale(coalesce(g.qty, 0))::text as ledger, trim_scale(coalesce(b.qty, 0))::text as balance
                   from ledger g
                   full join stock_balances b
                     on (b.plant_id, b.location_id, b.item_id, b.stock_status, b.ownership, b.uom_id) = (g.plant_id, g.location_id, g.item_id, g.stock_status, g.ownership, g.uom_id)
                    and b.lot_id is not distinct from g.lot_id
                  where coalesce(g.qty, 0) <> coalesce(b.qty, 0)
                  order by 1, 2, 3, 4, 5",
            );
            $differences = array_values(array_map(static fn ($r) => [
                'plantId' => (string) $r->plant_id, 'locationId' => (string) $r->location_id, 'itemId' => (string) $r->item_id,
                'lotId' => $r->lot_id === null ? null : (string) $r->lot_id, 'stockStatus' => (string) $r->stock_status,
                'ledger' => (string) $r->ledger, 'balance' => (string) $r->balance,
            ], $rows));
            if ($differences !== []) {
                $tenantId = (string) $db->selectOne('select c.tenant from ctx_api.context() c')->tenant;
                $this->audit->record($db, 'inventory.projection_mismatch', 'tenant', $tenantId, null, null,
                    ['differences' => count($differences), 'sample' => array_slice($differences, 0, 20)]);
                $this->outbox->publish($db, 'inventory.projection_mismatch', 'tenant', $tenantId, ['differences' => count($differences)]);
            }

            return $differences;
        });
    }
}
