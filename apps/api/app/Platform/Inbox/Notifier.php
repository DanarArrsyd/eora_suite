<?php

declare(strict_types=1);

namespace App\Platform\Inbox;

use App\Platform\Database\EoraPostgresConnection;

/**
 * Persisted inbox (doc 07, doc 17 §J) for notifications produced inside Core: rows are written in the command's own
 * transaction, so a notification exists exactly when its change committed. Cross-module notifications go through the
 * outbox later (doc 25 §6.4).
 */
final class Notifier
{
    /**
     * The event id is the command's audit event, so each recipient gets exactly one row (no ON CONFLICT: under RLS it
     * would apply the owner-only SELECT policy to rows addressed to other members).
     *
     * D-069 mitigation 2: every ACTIVE member who can administer access (`core.users_roles.post`, any scope), except the
     * acting administrator, is told when a role carrying sensitive permissions is granted.
     */
    public function sensitiveRoleGranted(EoraPostgresConnection $db, string $auditEventId, string $roleCode, string $roleName, string $granteeName, string $scopeType): int
    {
        return $db->affectingStatement(
            "insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, title, body)
             select distinct on (m.id) m.tenant_id, m.id, m.user_id, ?::uuid, 'access.sensitive_role_granted', 'WARNING', ?, ?
               from memberships m
               join role_assignments ra on ra.tenant_id = m.tenant_id and ra.membership_id = m.id and ra.revoked_at is null
                                        and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())
               join role_permissions rp on rp.tenant_id = ra.tenant_id and rp.role_id = ra.role_id and rp.permission_code = 'core.users_roles.post'
              where m.status = 'ACTIVE' and m.user_id <> (select c.actor from ctx_api.context() c)",
            [
                $auditEventId,
                'Sensitive role granted',
                mb_substr("{$roleCode} ({$roleName}) was granted to {$granteeName} at {$scopeType} scope. Review it in Administration → Roles & Permissions if this was not expected.", 0, 1000),
            ],
        );
    }
}
