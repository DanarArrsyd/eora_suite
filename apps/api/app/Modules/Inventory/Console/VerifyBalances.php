<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Application\BalanceVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Nightly (scheduler, BR-INV-11): checks in every provisioned tenant that the stock balances equal the ledger. */
final class VerifyBalances extends Command
{
    protected $signature = 'eora:inventory:verify-balances {--tenant= : Check this tenant id only}';

    protected $description = 'Check that stock balances equal the sum of the ledger';

    public function handle(BalanceVerification $verification): int
    {
        $only = $this->option('tenant');
        if (is_string($only) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $only) !== 1) {
            $this->error('The tenant is a lower-case UUID.');

            return self::INVALID;
        }
        $tenants = is_string($only)
            ? [$only]
            : array_map(static fn ($r) => (string) $r->tenant_id, DB::connection('pgsql_provisioner')->select('select tenant_id from prov_api.provisioned_tenants()'));
        $failed = 0;
        foreach ($tenants as $tenant) {
            $differences = $verification->verify($tenant);
            if ($differences !== []) {
                $failed++;
                // ids only: no names or quantities of a tenant in the application log
                Log::critical('inventory.projection_mismatch', ['tenant' => $tenant, 'differences' => count($differences)]);
                $this->error(sprintf('Tenant %s: %d balance(s) differ from the ledger.', $tenant, count($differences)));
            }
        }
        $this->info(sprintf('%d tenant(s) checked; %d with differences.', count($tenants), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
