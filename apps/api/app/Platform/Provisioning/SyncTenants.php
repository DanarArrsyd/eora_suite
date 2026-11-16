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
 * (system actor), each step changes only what differs from the baseline and is audited:
 *  1. template roles receive permissions added to their templates later (tenant roles have no editing path yet),
 *     templates added later become roles of the tenant (D-126), and a permission a template gave up leaves its roles
 *     (D-127);
 *  2. every plant has its virtual locations (ADR-0002 §3) — new plants get them from a trigger;
 *  3. the starter units KG, PCS and SHEET exist (doc 24 §3.2) — new tenants get them from a trigger.
 * Idempotent: a second run changes nothing.
 */
final class SyncTenants extends Command
{
    protected $signature = 'eora:tenants:sync';

    protected $description = 'Bring every tenant up to the current baseline (template permissions, virtual locations, starter units)';

    public function handle(TenantTransaction $transaction, RoleTemplates $templates, AuditRecorder $audit): int
    {
        $tenants = array_map(static fn ($r) => (string) $r->tenant_id, DB::connection('pgsql_provisioner')->select('select tenant_id from prov_api.provisioned_tenants()'));
        $roles = $plants = $units = 0;

        foreach ($tenants as $tenant) {
            [$r, $p, $u] = $transaction->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db): array => [
                $this->roles($db, $tenant, $templates, $audit),
                $this->virtualLocations($db, $audit),
                $this->starterUnits($db, $tenant, $audit),
            ], 'pgsql_provisioner');
            $roles += $r;
            $plants += $p;
            $units += $u;
        }

        $this->info(sprintf('%d tenant(s) checked; %d role(s), %d plant(s) and %d unit(s) updated.', count($tenants), $roles, $plants, $units));

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
        // template roles the tenant does not have yet (a template added later, e.g. SUP, D-126); only in a tenant whose
        // template roles were made at provisioning (a provisioning record without a tenant behind it is left alone)
        $absent = $db->select(
            'select t.code from role_templates t
              where not exists (select 1 from roles r where r.template_code = t.code)
                and exists (select 1 from roles r where r.template_code is not null)
              order by t.code',
        );
        if ($missing === [] && $absent === []) {
            return $this->revokeRemoved($db, $audit);
        }
        $templates->instantiate($db, $tenant);
        foreach ($absent as $template) {
            $role = $db->selectOne('select id from roles where template_code = ?', [$template->code]);
            if ($role !== null) {
                $audit->record($db, 'role.created_from_template', 'role', (string) $role->id, null, null, ['roleCode' => (string) $template->code]);
            }
        }
        foreach ($missing as $role) {
            /** @var list<string> $codes */
            $codes = json_decode((string) $role->codes, true);
            $audit->record($db, 'role.permissions_synced', 'role', (string) $role->id, null, null, ['roleCode' => (string) $role->code, 'added' => $codes]);
        }

        return count($missing) + count($absent) + $this->revokeRemoved($db, $audit);
    }

    /**
     * A permission its template no longer holds leaves the template role too (e.g. demand entry moved from PPC to DLV,
     * D-127); tenant roles mirror their template, so nothing tenant-made is lost. Audited per role.
     */
    private function revokeRemoved(EoraPostgresConnection $db, AuditRecorder $audit): int
    {
        $stale = $db->select(
            'select r.id, r.code, r.template_code, array_to_json(array_agg(rp.permission_code order by rp.permission_code)) as codes
               from roles r join role_permissions rp on rp.role_id = r.id
              where r.template_code is not null
                and not exists (select 1 from role_template_permissions tp where tp.template_code = r.template_code and tp.permission_code = rp.permission_code)
              group by r.id, r.code, r.template_code order by r.code',
        );
        foreach ($stale as $role) {
            /** @var list<string> $codes */
            $codes = json_decode((string) $role->codes, true);
            $db->delete(
                'delete from role_permissions rp where rp.role_id = ?
                    and not exists (select 1 from role_template_permissions tp where tp.template_code = ? and tp.permission_code = rp.permission_code)',
                [$role->id, $role->template_code],
            );
            $audit->record($db, 'role.permissions_revoked', 'role', (string) $role->id, null, null, ['roleCode' => (string) $role->code, 'removed' => $codes]);
        }

        return count($stale);
    }

    private function starterUnits(EoraPostgresConnection $db, string $tenant, AuditRecorder $audit): int
    {
        $added = $db->select(
            "insert into uoms (tenant_id, code, name, uom_class, decimals)
             select t.id, v.code, v.name, v.cls, v.dec
               from tenants t
              cross join (values ('KG', 'Kilogram', 'MASS', 3), ('PCS', 'Pieces', 'COUNT', 0), ('SHEET', 'Sheet', 'COUNT', 0)) as v (code, name, cls, dec)
              where t.id = ?
             on conflict (tenant_id, code) do nothing
             returning id, code",
            [$tenant],
        );
        foreach ($added as $row) {
            $audit->record($db, 'uom.synced', 'uom', (string) $row->id, null, null, ['code' => (string) $row->code]);
        }

        return count($added);
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
