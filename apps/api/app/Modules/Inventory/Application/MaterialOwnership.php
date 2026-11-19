<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Validation\ValidationException;

/** Immutable lot ownership; supplier identity and production customer are separate (D-135). */
final class MaterialOwnership
{
    /** @param list<array<string,mixed>> $consumptions
     * @return array{customer:?string,company:?string}
     */
    public static function forProduction(EoraPostgresConnection $db, string $wo, string $location, string $company, array $consumptions): array
    {
        $owners = [];
        foreach ($consumptions as $line) {
            $lot = isset($line['lotId']) ? strtolower((string) $line['lotId']) : null;
            $there = (string) $db->selectOne("select coalesce(sum(qty_signed),0)::text as qty from inventory_movements
                where wo_id=? and location_id=? and item_id=? and lot_id is not distinct from ?::uuid and stock_status='UNRESTRICTED'",
                [$wo, $location, strtolower((string) $line['itemId']), $lot])->qty;
            $qty = (string) $line['qty'];
            if (! is_numeric($there) || ! is_numeric($qty)) {
                throw new \LogicException('Consumption and ledger quantities must be numeric.');
            }
            $used = str_ends_with((string) $line['method'], '_REMAINING') ? bcsub($there, $qty, 6) : $qty;
            if (bccomp($used, '0', 6) <= 0) {
                continue;
            }
            $row = $lot === null ? null : $db->selectOne('select owner_customer_id,owner_company_id from lots where id=?', [$lot]);
            $customer = $row?->owner_customer_id;
            $ownerCompany = $customer === null ? ($row->owner_company_id ?? $company) : null;
            $owners[($customer === null ? 'OWN:'.$ownerCompany : 'CUSTOMER:'.$customer)] = ['customer' => $customer, 'company' => $ownerCompany];
        }
        if (count($owners) > 1) {
            throw ValidationException::withMessages(['consumptions' => 'Use one material owner per production posting. Report each owner in a separate run.']);
        }

        return array_values($owners)[0] ?? ['customer' => null, 'company' => $company];
    }

    /** Aggregate planning cannot silently treat restricted assets as shared company supply. */
    public static function requireAggregatePlanning(EoraPostgresConnection $db, string $plant): void
    {
        if ($db->selectOne("select 1 from stock_balances where plant_id=? and ownership='CUSTOMER' and qty>0 limit 1", [$plant]) !== null) {
            throw new DomainProblem(409, 'OWNER_PEGGING_REQUIRED', 'Customer material in stock',
                'This plant holds customer material. Plan it with work orders for that customer: MPS, MRP and Daily Recovery cannot keep customer stock separate yet.');
        }
    }
}
