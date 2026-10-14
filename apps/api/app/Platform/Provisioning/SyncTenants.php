<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use App\Platform\Access\RoleTemplates;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator command after a deployment whose migrations add per-tenant baseline data that forced RLS keeps out of the
 * migration owner's reach (D-072). For every provisioned tenant, in its own signed context as eora_provisioner
 * (system actor), each step adds only what is missing and is audited:
 *  1. template roles receive permissions added to their templates later (tenant roles have no editing path yet);
 *  2. every plant has its virtual locations (ADR-0002 §3) — new plants get them from a trigger.
 * Idempotent: a second run changes nothing.
 */
final class SyncTenants extends Command
{
    protected $signature = 'eora:tenants:sync';

    protected $description = 'Bring every tenant up to the current baseline (template permissions, virtual locations)';

    public function handle(TenantTransaction $transaction, RoleTemplates $templates, AuditRecorder $audit): int
    {
        $tenants = array_map(static fn ($r) => (string) $r->tenant_id, DB::connection('pgsql_provisioner')->select('select tenant_id from prov_api.provisioned_tenants()'));
        $roles = $plants = 0;

        foreach ($tenants as $tenant) {
            [$r, $p] = $transaction->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db): array => [
                $this->roles($db, $tenant, $templates, $audit),
                $this->virtualLocations($db, $audit),
            ], 'pgsql_provisioner');
            $roles += $r;
            $plants += $p;
        }

        $this->info(sprintf('%d tenant(s) checked; %d role(s) and %d plant(s) updated.', count($tenants), $roles, $plants));

        return self::SUCCESS;
    }

    private function roles(EoraPostgresConnection $db, string $tenant, RoleTemplates $templates, AuditRecorder $audit): int
    {
        $missing = $db->select(
            'select r.id, r.code, array_to_json(array_agg(tp.permission_code order by tp.permission_code)) as codes
               from roles r join role_template_permissions tp on tp.template_code = r.template_code
              where not exists (select 1 from role_permissions rp where rp.role_id = r.id and rp.permission_code = tp.permission_code)
              group by r.id, r.code order by r.code',
        );
        if ($missing === []) {
            return 0;
        }
        $templates->instantiate($db, $tenant);
        foreach ($missing as $role) {
            /** @var list<string> $codes */
            $codes = json_decode((string) $role->codes, true);
            $audit->record($db, 'role.permissions_synced', 'role', (string) $role->id, null, null, ['roleCode' => (string) $role->code, 'added' => $codes]);
        }

        return count($missing);
    }

    private function virtualLocations(EoraPostgresConnection $db, AuditRecorder $audit): int
    {
        $added = $db->select(
            "insert into locations (tenant_id, plant_id, code, name, location_type)
             select p.tenant_id, p.id, v.t, v.n, v.t
               from plants p
              cross join (values ('V_SUPPLIER', 'Supplier (virtual)'), ('V_CUSTOMER', 'Customer (virtual)'),
                                 ('V_CONSUMED', 'Consumed (virtual)'), ('V_PRODUCED', 'Produced (virtual)'),
                                 ('V_ADJUSTMENT', 'Adjustment (virtual)'), ('V_OPENING', 'Opening balance (virtual)')) as v (t, n)
             on conflict (tenant_id, plant_id, code) do nothing
             returning plant_id, code",
        );
        $byPlant = [];
        foreach ($added as $row) {
            $byPlant[(string) $row->plant_id][] = (string) $row->code;
        }
        foreach ($byPlant as $plant => $codes) {
            $audit->record($db, 'plant.virtual_locations_synced', 'plant', $plant, null, null, ['added' => $codes], $plant);
        }

        return count($byPlant);
    }
}
