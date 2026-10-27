<?php

declare(strict_types=1);

namespace App\Platform\Export;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Hourly (scheduler, D-092): deletes exported files older than 24 hours in every provisioned tenant (ExportExpiry). */
final class ExpireExports extends Command
{
    protected $signature = 'eora:exports:expire';

    protected $description = 'Delete exported files older than 24 hours';

    public function handle(ExportExpiry $expiry): int
    {
        $tenants = array_map(static fn ($r) => (string) $r->tenant_id, DB::connection('pgsql_provisioner')->select('select tenant_id from prov_api.provisioned_tenants()'));
        $deleted = 0;
        foreach ($tenants as $tenant) {
            $deleted += $expiry->expire($tenant);
        }
        $this->info(sprintf('%d tenant(s) checked; %d export file(s) deleted.', count($tenants), $deleted));

        return self::SUCCESS;
    }
}
