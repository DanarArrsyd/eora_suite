<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Database\EoraPostgresConnection;
use stdClass;

/**
 * Privileged-reset notification (doc 25 §2.3, D-B1-25, R-26/R-38/R-44): when a user who holds sensitive permissions in
 * this tenant resets the password, the tenant administrators (`core.users_roles.post`) and the user, each when ACTIVE
 * here, get one inbox notification — no secret, token or link. Only `auth.password_reset_consumed` from `auth_web` is
 * trusted; anything else is refused and recorded as `outbox.untrusted_event`.
 */
final class PrivilegedResetNotifier implements OutboxConsumer
{
    public function name(): string
    {
        return 'privileged_reset_notifier';
    }

    public function handle(EoraPostgresConnection $db, stdClass $event): void
    {
        $payload = json_decode((string) $event->payload, true);
        $user = is_array($payload) && is_string($payload['user_id'] ?? null) ? $payload['user_id'] : '';

        if ($event->source !== 'auth_web' || $event->event_type !== 'auth.password_reset_consumed'
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $user) !== 1) {
            $db->insert(
                "insert into security_events (tenant_id, source, action, resource, requested_id, detail_code)
                 values ((select c.tenant from ctx_api.context() c), 'app', 'outbox.untrusted_event', 'outbox_events', ?, 'REFUSED')",
                [str_replace('-', '', (string) $event->id)],
            );

            return;
        }

        // privileged = effective sensitive permissions of the user's currently valid assignments here (D-B1-25)
        $privileged = $db->selectOne(
            'select 1 as yes
               from role_assignments ra
               join memberships m on m.tenant_id = ra.tenant_id and m.id = ra.membership_id and m.user_id = ?
               join role_permissions rp on rp.tenant_id = ra.tenant_id and rp.role_id = ra.role_id
               join permissions p on p.code = rp.permission_code and p.sensitive
              where ra.revoked_at is null and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())
              limit 1',
            [$user],
        );
        if ($privileged === null) {
            return;
        }

        $name = (string) ($db->selectOne('select display_name from users where id = ?', [$user])->display_name ?? 'A member');
        $at = (string) $db->selectOne("select to_char(e.occurred_at at time zone t.timezone, 'YYYY-MM-DD HH24:MI') || ' (' || t.timezone || ')' as at
                                         from outbox_events e join tenants t on t.id = e.tenant_id where e.id = ?", [$event->id])->at;

        // recipients: ACTIVE administrators ∪ the ACTIVE reset owner, one row each (DISTINCT ON membership)
        $db->insert(
            "insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, title, body)
             select distinct on (m.id) m.tenant_id, m.id, m.user_id, ?::uuid, 'access.privileged_password_reset', 'WARNING', ?, ?
               from memberships m
              where m.status = 'ACTIVE'
                and (m.user_id = ?
                     or exists (select 1 from role_assignments ra
                                  join role_permissions rp on rp.tenant_id = ra.tenant_id and rp.role_id = ra.role_id
                                                           and rp.permission_code = 'core.users_roles.post'
                                 where ra.tenant_id = m.tenant_id and ra.membership_id = m.id and ra.revoked_at is null
                                   and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())))",
            [
                $event->id,
                'Password reset on a privileged account',
                mb_substr("The password of {$name} was reset at {$at}. If this was not expected, suspend the member in Administration → Users and contact support.", 0, 1000),
                $user,
            ],
        );
    }
}
