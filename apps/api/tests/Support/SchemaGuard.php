<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;

/**
 * Catalog checks shared by the group A schema guard and its self-tests (doc 25 §5.4, D-056 P-04).
 */
final class SchemaGuard
{
    /**
     * Tenant tables (public tables with a tenant_id column) whose RLS is missing, not forced, without any policy, or
     * with a policy expression that does not bind tenant_id to the signed context.
     *
     * @return list<string>
     */
    public static function tenantPolicyViolations(Connection $owner): array
    {
        $tables = $owner->select(
            "select c.oid, c.relname, c.relrowsecurity, c.relforcerowsecurity
               from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where c.relkind in ('r', 'p') and n.nspname = 'public'
                and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped)
              order by c.relname",
        );
        $violations = [];

        foreach ($tables as $table) {
            if (! $table->relrowsecurity || ! $table->relforcerowsecurity) {
                $violations[] = "{$table->relname}: row level security not enabled and forced";
            }

            $policies = $owner->select(
                'select polname, polcmd, pg_get_expr(polqual, polrelid) as qual, pg_get_expr(polwithcheck, polrelid) as withcheck
                   from pg_policy where polrelid = ? order by polname',
                [$table->oid],
            );

            if ($policies === []) {
                $violations[] = "{$table->relname}: no policy (the table would be unusable or rely on an unreviewed path)";
            }

            foreach ($policies as $policy) {
                $key = "{$table->relname}.{$policy->polname}";

                if (array_key_exists($key, SecurityBaseline::TENANT_POLICY_EXCEPTIONS)) {
                    continue;
                }

                // SELECT, UPDATE, DELETE and ALL filter rows with USING; INSERT, UPDATE and ALL check rows with WITH CHECK
                // (ALL/UPDATE fall back to USING when WITH CHECK is absent).
                $needsUsing = in_array($policy->polcmd, ['r', 'w', 'd', '*'], true);
                $check = $policy->withcheck ?? (in_array($policy->polcmd, ['w', '*'], true) ? $policy->qual : null);
                $needsCheck = in_array($policy->polcmd, ['a', 'w', '*'], true);

                foreach (['USING' => [$needsUsing, $policy->qual], 'WITH CHECK' => [$needsCheck, $check]] as $clause => [$needed, $expr]) {
                    if ($needed && ! self::bindsTenant($expr)) {
                        $violations[] = "{$key}: {$clause} does not bind tenant_id to ".SecurityBaseline::TENANT_CONTEXT_CALL;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Differences between the SECURITY DEFINER functions in the database and the reviewed allowlist: signature,
     * owner and the complete EXECUTE grantee set must match exactly.
     *
     * @return array{actual: array<string, array{owner: string, execute: list<string>}>, expected: array<string, array{owner: string, execute: list<string>}>}
     */
    public static function definers(Connection $owner): array
    {
        $rows = $owner->select(
            "select p.oid::regprocedure::text as signature, pg_get_userbyid(p.proowner) as owner,
                    coalesce((select array_to_json(array_agg(distinct case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end
                                                  order by case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end))
                                from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                               where a.privilege_type = 'EXECUTE' and a.grantee <> p.proowner), '[]') as execute
               from pg_proc p join pg_namespace n on n.oid = p.pronamespace
              where p.prosecdef and n.nspname not in ('pg_catalog', 'information_schema')
                and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
              order by 1",
        );
        $actual = [];

        foreach ($rows as $row) {
            /** @var list<string> $execute */
            $execute = json_decode((string) $row->execute, true);
            $actual[(string) $row->signature] = ['owner' => (string) $row->owner, 'execute' => $execute];
        }

        $expected = SecurityBaseline::DEFINER_FUNCTIONS;
        ksort($expected);

        return ['actual' => $actual, 'expected' => $expected];
    }

    private static function bindsTenant(?string $expression): bool
    {
        return $expression !== null
            && str_contains($expression, 'tenant_id')
            && str_contains($expression, SecurityBaseline::TENANT_CONTEXT_CALL);
    }
}
