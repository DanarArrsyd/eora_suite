<?php

declare(strict_types=1);

namespace App\Platform\Access;

use App\Platform\Database\EoraPostgresConnection;

/**
 * Copies the default role templates (doc 18 §1, D-060; TENANT_ADMIN D-B1-16) into a tenant as its roles, inside that
 * tenant's signed context. Idempotent: an existing role code is left as it is and a re-run only adds missing template
 * permissions (M1 has no path that edits tenant roles).
 */
final class RoleTemplates
{
    public function instantiate(EoraPostgresConnection $db, string $tenantId): void
    {
        $db->insert(
            'insert into roles (tenant_id, code, name, template_code)
             select ?, t.code, t.name, t.code from role_templates t order by t.code
             on conflict (tenant_id, code) do nothing',
            [$tenantId],
        );
        $db->insert(
            'insert into role_permissions (tenant_id, role_id, permission_code)
             select r.tenant_id, r.id, tp.permission_code
               from roles r join role_template_permissions tp on tp.template_code = r.template_code
              where r.tenant_id = ?
             on conflict do nothing',
            [$tenantId],
        );
    }
}
