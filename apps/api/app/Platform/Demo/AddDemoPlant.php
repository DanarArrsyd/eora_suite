<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Local UAT / stack E2E data (doc 28 §3 item 3): adds a plant to an existing tenant's first company so the plant switch
 * can be exercised before master-data maintenance exists (M2). Refused in production; runs as eora_provisioner in the
 * tenant's signed context (system actor) and audits the change like any other write.
 */
final class AddDemoPlant extends Command
{
    protected $signature = 'eora:demo:plant
        {tenant : Tenant id (printed by eora:tenant:provision)}
        {code : Plant code (A-Z, 0-9, _ -)}
        {name : Plant name}';

    protected $description = 'Add a plant to an existing tenant (local UAT and stack E2E only)';

    public function handle(TenantTransaction $transaction, AuditRecorder $audit): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Demo plants are never added in production.');

            return self::FAILURE;
        }

        $tenant = (string) $this->argument('tenant');
        $code = (string) $this->argument('code');
        $name = trim((string) $this->argument('name'));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant) !== 1) {
            $this->error('The tenant must be a tenant id (UUID).');

            return self::INVALID;
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 || $name === '' || mb_strlen($name) > 200) {
            $this->error('The plant code must be 1–20 characters A-Z, 0-9, "_" or "-", and the name 1–200 characters.');

            return self::INVALID;
        }

        try {
            $plant = $transaction->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), function (EoraPostgresConnection $db) use ($tenant, $code, $name, $audit): ?string {
                $home = $db->selectOne('select c.id as company, t.timezone from companies c join tenants t on t.id = c.tenant_id order by c.created_at, c.id limit 1');
                if ($home === null) {
                    return null; // unknown tenant: RLS shows nothing
                }
                $id = (string) $db->selectOne(
                    'insert into plants (tenant_id, company_id, code, name, timezone) values (?, ?, ?, ?, ?) returning id',
                    [$tenant, $home->company, $code, $name, $home->timezone],
                )->id;
                $audit->record($db, 'plant.created', 'plant', $id, null, ['code' => $code, 'name' => $name], ['code' => $code, 'via' => 'demo-plant'], $id);

                return $id;
            }, 'pgsql_provisioner');
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $this->error("Plant {$code} already exists in this tenant.");

                return self::FAILURE;
            }
            throw $e;
        }

        if ($plant === null) {
            $this->error('No tenant with this id.');

            return self::FAILURE;
        }
        $this->info("Plant {$code} added: {$plant}");

        return self::SUCCESS;
    }
}
