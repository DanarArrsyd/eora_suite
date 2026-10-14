<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Auth\Delivery\ConsoleInvitation;
use App\Platform\Auth\PasswordHasher;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Provisioning\TenantSpec;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Synthetic demo tenant for local UAT (doc 28 §1 "Untuk testing Anda"): one tenant, one company, one plant and one
 * user per role template (doc 18 §1, D-060), every user active with the same demo password.
 *
 * Everything goes through the production paths, never around them: TenantProvisioner (first administrator), the
 * provisioner role in the tenant's signed context for further members and their role assignments (system actor), the
 * console delivery channel for the one-time invitation secrets, auth_api.consume_invitation for the first password and
 * the membership activation as the user under RLS. Nothing here exists in the demo data but what those paths create.
 */
final class DemoSeeder
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly ConsoleInvitation $console,
        private readonly PasswordHasher $passwords,
        private readonly TenantTransaction $transaction,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<array{role: string, name: string, email: string, scope: string}>
     */
    public function seed(DemoSpec $spec, string $password): array
    {
        $result = $this->provisioner->provision(new TenantSpec(
            tenantCode: $spec->code,
            tenantName: $spec->name,
            adminEmail: $spec->email('tenant-admin'),
            companyCode: 'DEMO',
            companyName: $spec->name.' (synthetic)',
            plantCode: 'P1',
            plantName: 'Plant 1 (demo)',
            productionDayStartsAt: '07:00',
            timezone: 'Asia/Jakarta',
        ), console: true);
        if (! $result->created) {
            throw new DemoAlreadySeeded($spec->code);
        }
        $tenant = $result->tenantId;

        $roles = $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->select(
            "select r.id, r.code, r.name, coalesce(t.default_scope_type, 'TENANT') as scope
               from roles r left join role_templates t on t.code = r.template_code
              where r.code <> 'TENANT_ADMIN' order by r.code",
        ));
        $plant = (string) $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->selectOne("select id from plants where code = 'P1'")->id);

        $members = [['membership' => $result->adminMembershipId, 'role' => 'TENANT_ADMIN', 'name' => 'Tenant Administrator', 'email' => $spec->email('tenant-admin'), 'scope' => 'TENANT']];
        foreach ($roles as $role) {
            $members[] = $this->addMember($spec, $tenant, $plant, $role);
        }

        $hash = $this->passwords->hash($password);
        foreach ($members as $m) {
            $this->activate($tenant, $m['membership'], $hash);
        }

        return array_map(static fn (array $m) => ['role' => $m['role'], 'name' => $m['name'], 'email' => $m['email'], 'scope' => $m['scope'] === 'PLANT' ? 'Plant P1' : 'Whole tenant'], $members);
    }

    /** @return array{membership: string, role: string, name: string, email: string, scope: string} */
    private function addMember(DemoSpec $spec, string $tenant, string $plant, stdClass $role): array
    {
        $email = $spec->email(strtolower((string) $role->code));
        $user = DB::connection('pgsql_auth')->selectOne('select auth_api.invite_identity(?) as id', [$email])->id
            ?? throw new RuntimeException('Identity creation is capped right now; run the demo seeder again later.');
        $scope = (string) $role->scope;

        $membership = $this->inTenant($tenant, function (EoraPostgresConnection $db) use ($tenant, $user, $role, $scope, $plant): string {
            $membership = (string) $db->selectOne("insert into memberships (tenant_id, user_id, status) values (?, ?, 'INVITED') returning id", [$tenant, $user])->id;
            $db->insert("insert into invitation_dispatch_requests (tenant_id, membership_id, reason) values (?, ?, 'CONSOLE')", [$tenant, $membership]);
            $assignment = (string) $db->selectOne(
                'insert into role_assignments (tenant_id, membership_id, role_id, scope_type, plant_id, granted_by, grant_reason)
                 values (?, ?, ?, ?, ?, ?, ?) returning id',
                [$tenant, $membership, $role->id, $scope, $scope === 'PLANT' ? $plant : null, TenantProvisioner::SYSTEM_ACTOR, 'Demo seed: synthetic UAT user'],
            )->id;
            $this->audit->record($db, 'membership.invited', 'membership', $membership, null, ['status' => 'INVITED', 'userId' => (string) $user], ['userId' => (string) $user, 'via' => 'demo-seed']);
            $this->audit->record($db, 'role_assignment.granted', 'role_assignment', $assignment, null, ['roleCode' => (string) $role->code, 'scopeType' => $scope], [
                'membershipId' => $membership, 'roleCode' => (string) $role->code, 'scopeType' => $scope, 'via' => 'demo-seed',
            ], $scope === 'PLANT' ? $plant : null);

            return $membership;
        });

        return ['membership' => $membership, 'role' => (string) $role->code, 'name' => (string) $role->name, 'email' => $email, 'scope' => $scope];
    }

    /** Console delivery → first password through the invitation → membership ACTIVE as the user (AuthController path). */
    private function activate(string $tenant, string $membership, string $hash): void
    {
        $link = $this->console->deliver($membership) ?? throw new RuntimeException('No invitation could be issued for a demo member.');
        $secret = substr($link, (int) strrpos($link, '#token=') + 7);
        $accepted = DB::connection('pgsql_auth')->selectOne('select user_id, membership_id from auth_api.consume_invitation(?, ?)', [$secret, $hash]);
        if ($accepted === null || $accepted->user_id === null) {
            throw new RuntimeException('A demo invitation could not be accepted.');
        }
        $this->transaction->run(new TenantContext($tenant, (string) $accepted->user_id), function (EoraPostgresConnection $db) use ($membership): void {
            if ($db->update("update memberships set status = 'ACTIVE', activated_at = now() where id = ? and status = 'INVITED'", [$membership]) === 1) {
                $this->audit->record($db, 'membership.activated', 'membership', $membership, ['status' => 'INVITED'], ['status' => 'ACTIVE']);
            }
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(EoraPostgresConnection): T  $work
     * @return T
     */
    private function inTenant(string $tenant, \Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::forTenant($tenant, TenantProvisioner::SYSTEM_ACTOR), $work, 'pgsql_provisioner');
    }
}
