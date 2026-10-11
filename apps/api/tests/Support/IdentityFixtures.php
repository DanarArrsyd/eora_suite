<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Drives the identity flow through the real roles (doc 25 §2.4, §11 group B/C): provisioner → identity + membership +
 * dispatch request; delivery role → claim, issue, acknowledge; web auth role → consume. No superuser, no owner.
 */
final class IdentityFixtures
{
    public static function email(): string
    {
        return 'u'.Str::lower(Str::random(10)).'@example.test';
    }

    /** @return array{user: string, membership: string} */
    public static function invite(array $tenant, string $email, string $reason = 'INITIAL'): array
    {
        $user = (string) DB::connection('pgsql_provisioner')->selectOne('select auth_api.provision_identity(?) as id', [$email])->id;

        $membership = app(TenantTransaction::class)->run(TenantContext::forTenant($tenant['tenant'], $tenant['actor']), function (EoraPostgresConnection $db) use ($tenant, $user, $reason): string {
            $existing = $db->selectOne('select id from memberships where user_id = ?', [$user]);
            $id = $existing->id ?? (string) $db->selectOne("insert into memberships (tenant_id, user_id, status) values (?, ?, 'INVITED') returning id", [$tenant['tenant'], $user])->id;
            $db->insert('insert into invitation_dispatch_requests (tenant_id, membership_id, reason) values (?, ?, ?)', [$tenant['tenant'], $id, $reason]);

            return (string) $id;
        }, 'pgsql_provisioner');

        return ['user' => $user, 'membership' => $membership];
    }

    /**
     * Runs one delivery round like the worker: convert dispatch requests, claim, issue, acknowledge.
     *
     * @return array{delivery: string, token: string, recipient: string, secret: string}|null
     */
    public static function deliver(bool $acknowledge = true): ?array
    {
        $delivery = DB::connection('pgsql_delivery');
        $delivery->select('select auth_api.claim_invitation_dispatch(?, ?)', ['test-worker', 100]);

        do {
            $claim = $delivery->selectOne('select * from auth_api.claim_delivery(?)', ['test-worker']);

            if ($claim === null || $claim->delivery_id === null) {
                return null;
            }

            // like the worker: NULL = the target is gone, the delivery was cancelled; take the next one
            $secret = $delivery->selectOne('select auth_api.issue_invitation_secret(?, ?) as s', [$claim->delivery_id, $claim->claim_token])->s;
        } while ($secret === null);
        $secret = (string) $secret;

        if ($acknowledge) {
            $delivery->select('select auth_api.mark_delivery_result(?, ?, true, null)', [$claim->delivery_id, $claim->claim_token]);
        }

        return ['delivery' => (string) $claim->delivery_id, 'token' => (string) $claim->claim_token, 'recipient' => (string) $claim->recipient, 'secret' => $secret];
    }

    /** @return object{user_id: ?string, tenant_id: ?string, membership_id: ?string}|null */
    public static function accept(string $secret, string $passwordHash = '$argon2id$v=19$m=65536,t=3,p=1$dGVzdHNhbHQ$dGVzdGhhc2g'): ?object
    {
        $row = DB::connection('pgsql_auth')->selectOne('select * from auth_api.consume_invitation(?, ?)', [$secret, $passwordHash]);

        return $row?->user_id === null ? null : $row;
    }
}
