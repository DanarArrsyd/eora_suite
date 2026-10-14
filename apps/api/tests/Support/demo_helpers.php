<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* Demo seeder helpers shared by the demo tests (doc 28 §1). */

const DEMO_PASSWORD = 'synthetic demo passphrase 2026';

function demoCode(): string
{
    return 'demo-'.strtolower(Str::random(8));
}

/** @return array{tenant: string, members: list<stdClass>} rows read as eora_provisioner in the demo tenant */
function demoState(string $code): array
{
    $key = app(SecurityHasher::class)->hash($code."\n"."tenant-admin@{$code}.test");
    $tenant = (string) DB::connection('pgsql_provisioner')->selectOne('select tenant_id from prov_api.provision_lookup(?, ?)', [$code, $key])->tenant_id;
    $members = app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), fn (EoraPostgresConnection $db) => $db->select(
        'select u.email_display as email, m.status, r.code as role, ra.scope_type, p.code as plant
           from memberships m join users u on u.id = m.user_id
           join role_assignments ra on ra.membership_id = m.id and ra.revoked_at is null
           join roles r on r.id = ra.role_id
           left join plants p on p.id = ra.plant_id
          order by r.code',
    ), 'pgsql_provisioner');

    return ['tenant' => $tenant, 'members' => $members];
}
