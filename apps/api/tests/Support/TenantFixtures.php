<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Str;

/**
 * Creates isolated tenants the way provisioning does (doc 25 §2.5): as eora_provisioner, inside the new tenant's own
 * signed context — never as superuser or owner, so fixtures cannot bypass the policies under test.
 */
final class TenantFixtures
{
    /** @return array{tenant: string, company: string, plants: list<string>, actor: string} */
    public static function provision(int $plants = 2): array
    {
        $tenant = Str::uuid7()->toString();
        $actor = Str::uuid()->toString();
        $company = Str::uuid7()->toString();
        $plantIds = [];

        app(TenantTransaction::class)->run(TenantContext::forTenant($tenant, $actor), function (EoraPostgresConnection $db) use ($tenant, $company, $plants, &$plantIds): void {
            $db->insert('insert into tenants (id, code, name) values (?, ?, ?)', [$tenant, 't-'.substr(str_replace('-', '', $tenant), -12), 'Test tenant']);
            $db->insert('insert into companies (tenant_id, id, code, legal_name) values (?, ?, ?, ?)', [$tenant, $company, 'CO1', 'Test company']);

            for ($i = 1; $i <= $plants; $i++) {
                $plantIds[] = $id = Str::uuid7()->toString();
                $db->insert('insert into plants (tenant_id, id, company_id, code, name) values (?, ?, ?, ?, ?)', [$tenant, $id, $company, "P{$i}", "Plant {$i}"]);
            }

            $db->update("update tenants set status = 'ACTIVE', updated_at = now(), version = version + 1 where id = ?", [$tenant]);
        }, 'pgsql_provisioner');

        return ['tenant' => $tenant, 'company' => $company, 'plants' => $plantIds, 'actor' => $actor];
    }
}
