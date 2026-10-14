<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Audit\AuditRecorder;
use App\Platform\Auth\Delivery\ConsoleInvitation;
use App\Platform\Auth\PasswordHasher;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Provisioning\TenantProvisioner;
use App\Platform\Provisioning\TenantSpec;
use App\Platform\Security\SecurityHasher;
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
 * the membership activation as the user under RLS.
 *
 * Resumable: every step is idempotent, so a run interrupted by a transient failure (identity cap, delivery, database)
 * is completed by running the seeder again with the same password. A complete demo tenant is refused without any write.
 */
final class DemoSeeder
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly ConsoleInvitation $console,
        private readonly PasswordHasher $passwords,
        private readonly TenantTransaction $transaction,
        private readonly AuditRecorder $audit,
        private readonly SecurityHasher $hasher,
    ) {}

    /**
     * @param  bool  $passwordGiven  false when the password was generated for this run (a resume needs the original one)
     * @return array{resumed: bool, accounts: list<array{role: string, name: string, email: string, scope: string}>}
     */
    public function seed(DemoSpec $spec, string $password, bool $passwordGiven): array
    {
        $adminEmail = $spec->email('tenant-admin');
        $existing = DB::connection('pgsql_provisioner')->selectOne(
            'select tenant_id, status, admin_membership_id from prov_api.provision_lookup(?, ?)',
            [$spec->code, $this->hasher->hash($spec->code."\n".$adminEmail)],
        );

        if ($existing !== null && $existing->status !== 'STARTED') {
            // read-only check first: a complete demo tenant is refused without any write
            $statuses = $this->inTenant((string) $existing->tenant_id, fn (EoraPostgresConnection $db) => array_column($db->select(
                'select m.status from memberships m join users u on u.id = m.user_id where u.email_display like ?',
                ['%@'.$spec->code.'.test'],
            ), 'status'));
            $roleCount = (int) $this->inTenant((string) $existing->tenant_id, fn (EoraPostgresConnection $db) => $db->selectOne('select count(*) as n from roles')->n);
            if (count($statuses) >= $roleCount && array_unique($statuses) === ['ACTIVE']) {
                throw new DemoAlreadySeeded($spec->code);
            }
            if (in_array('ACTIVE', $statuses, true) && ! $passwordGiven) {
                throw new DemoResumeNeedsPassword($spec->code);
            }
        }

        $resumed = $existing !== null;
        if ($existing === null || $existing->status === 'STARTED') {
            $result = $this->provisioner->provision(new TenantSpec(
                tenantCode: $spec->code,
                tenantName: $spec->name,
                adminEmail: $adminEmail,
                companyCode: 'DEMO',
                companyName: $spec->name.' (synthetic)',
                plantCode: 'P1',
                plantName: 'Plant 1 (demo)',
                productionDayStartsAt: '07:00',
                timezone: 'Asia/Jakarta',
            ), console: true);
            $tenant = $result->tenantId;
            $adminMembership = $result->adminMembershipId;
            $adminDispatched = true; // provisioning queued the console dispatch (new tenant or re-queued)
        } else {
            $tenant = (string) $existing->tenant_id;
            $adminMembership = (string) $existing->admin_membership_id;
            $adminDispatched = false;
        }

        $roles = $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->select(
            "select r.id, r.code, r.name, coalesce(t.default_scope_type, 'TENANT') as scope
               from roles r left join role_templates t on t.code = r.template_code
              where r.code <> 'TENANT_ADMIN' order by r.code",
        ));
        $plant = (string) $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->selectOne("select id from plants where code = 'P1'")->id);

        $members = [['membership' => $adminMembership, 'dispatched' => $adminDispatched, 'role' => 'TENANT_ADMIN', 'name' => 'Tenant Administrator', 'email' => $adminEmail, 'scope' => 'TENANT']];
        foreach ($roles as $role) {
            $members[] = $this->ensureMember($spec, $tenant, $plant, $role);
        }

        $hash = $this->passwords->hash($password);
        foreach ($members as $m) {
            $this->ensureActive($tenant, $m['membership'], $m['dispatched'], $hash);
        }

        return [
            'resumed' => $resumed,
            'accounts' => array_map(static fn (array $m) => ['role' => $m['role'], 'name' => $m['name'], 'email' => $m['email'], 'scope' => $m['scope'] === 'PLANT' ? 'Plant P1' : 'Whole tenant'], $members),
        ];
    }

    /**
     * Membership and role assignment for one role template, created only when missing.
     *
     * @return array{membership: string, dispatched: bool, role: string, name: string, email: string, scope: string}
     */
    private function ensureMember(DemoSpec $spec, string $tenant, string $plant, stdClass $role): array
    {
        $email = $spec->email(strtolower((string) $role->code));
        $user = DB::connection('pgsql_auth')->selectOne('select auth_api.invite_identity(?) as id', [$email])->id
            ?? throw new RuntimeException('Identity creation is capped right now. Run the demo seeder again later with the same --password; it continues where it stopped.');
        $scope = (string) $role->scope;

        $membership = $this->inTenant($tenant, function (EoraPostgresConnection $db) use ($tenant, $user, $role, $scope, $plant): string {
            $membership = $db->selectOne('select id from memberships where user_id = ?', [$user])->id ?? null;
            if ($membership === null) {
                $membership = (string) $db->selectOne("insert into memberships (tenant_id, user_id, status) values (?, ?, 'INVITED') returning id", [$tenant, $user])->id;
                $this->audit->record($db, 'membership.invited', 'membership', $membership, null, ['status' => 'INVITED', 'userId' => (string) $user], ['userId' => (string) $user, 'via' => 'demo-seed']);
            }
            $assigned = $db->selectOne('select 1 as ok from role_assignments where membership_id = ? and role_id = ? and revoked_at is null', [$membership, $role->id]);
            if ($assigned === null) {
                $assignment = (string) $db->selectOne(
                    'insert into role_assignments (tenant_id, membership_id, role_id, scope_type, plant_id, granted_by, grant_reason)
                     values (?, ?, ?, ?, ?, ?, ?) returning id',
                    [$tenant, $membership, $role->id, $scope, $scope === 'PLANT' ? $plant : null, TenantProvisioner::SYSTEM_ACTOR, 'Demo seed: synthetic UAT user'],
                )->id;
                $this->audit->record($db, 'role_assignment.granted', 'role_assignment', $assignment, null, ['roleCode' => (string) $role->code, 'scopeType' => $scope], [
                    'membershipId' => $membership, 'roleCode' => (string) $role->code, 'scopeType' => $scope, 'via' => 'demo-seed',
                ], $scope === 'PLANT' ? $plant : null);
            }

            return (string) $membership;
        });

        return ['membership' => $membership, 'dispatched' => false, 'role' => (string) $role->code, 'name' => (string) $role->name, 'email' => $email, 'scope' => $scope];
    }

    /** INVITED → console delivery → first password through the invitation → ACTIVE as the user (AuthController path). */
    private function ensureActive(string $tenant, string $membership, bool $dispatched, string $hash): void
    {
        $status = $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select status from memberships where id = ?', [$membership])->status ?? null);
        if ($status !== 'INVITED') {
            return; // already active (earlier run), or not invitable
        }
        if (! $dispatched) {
            $this->inTenant($tenant, fn (EoraPostgresConnection $db) => $db->insert(
                "insert into invitation_dispatch_requests (tenant_id, membership_id, reason) values (?, ?, 'CONSOLE')",
                [$tenant, $membership],
            ));
        }

        $link = $this->console->deliver($membership) ?? throw new RuntimeException('No invitation could be issued for a demo member (send cap of 5 per 24 h?). Run the seeder again later with the same --password.');
        $secret = substr($link, (int) strrpos($link, '#token=') + 7);
        $accepted = DB::connection('pgsql_auth')->selectOne('select user_id from auth_api.consume_invitation(?, ?)', [$secret, $hash]);
        if ($accepted === null || $accepted->user_id === null) {
            throw new RuntimeException('A demo invitation could not be accepted. Run the seeder again with the same --password.');
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
