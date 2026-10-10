<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\SecurityBaseline;

/*
 * Group A — schema guard (doc 25 §5.4, §11; D-056 P-04). Reads the catalog through the migrations owner after
 * `migrate --database=pgsql_owner`; fails the build on any drift from the reviewed baseline.
 */

function owner(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return list<string> */
function column(string $sql, array $bindings = []): array
{
    return array_map(static fn (object $row): string => (string) array_values((array) $row)[0], owner()->select($sql, $bindings));
}

it('connects to the catalog as the non-superuser migrations owner', function () {
    $row = owner()->selectOne('select current_user as u, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');

    expect($row->u)->toBe(SecurityBaseline::MIGRATIONS_OWNER)
        ->and($row->rolsuper)->toBeFalse()
        ->and($row->rolbypassrls)->toBeFalse();
})->group('integration', 'schema');

it('keeps every EORA role free of superuser, BYPASSRLS, role and database creation', function () {
    $roles = [...SecurityBaseline::RUNTIME_ROLES, ...SecurityBaseline::DEFINER_OWNERS, SecurityBaseline::MIGRATIONS_OWNER];
    $offenders = column(
        'select rolname from pg_roles where rolname = any(?) and (rolsuper or rolbypassrls or rolcreaterole or rolcreatedb or rolreplication)',
        ['{'.implode(',', $roles).'}'],
    );
    $present = column('select rolname from pg_roles where rolname = any(?)', ['{'.implode(',', $roles).'}']);

    expect($offenders)->toBe([])
        ->and($present)->toHaveCount(count($roles));
})->group('integration', 'schema');

it('never lets definer owners log in and always lets runtime roles log in', function () {
    $nologin = column('select rolname from pg_roles where rolname = any(?) and rolcanlogin', ['{'.implode(',', SecurityBaseline::DEFINER_OWNERS).'}']);
    $login = column('select rolname from pg_roles where rolname = any(?) and not rolcanlogin', ['{'.implode(',', SecurityBaseline::RUNTIME_ROLES).'}']);

    expect($nologin)->toBe([])->and($login)->toBe([]);
})->group('integration', 'schema');

it('has no member of the migrations owner or of any definer owner', function () {
    $members = column(
        'select r.rolname || \' <- \' || m.rolname from pg_auth_members am
           join pg_roles r on r.oid = am.roleid join pg_roles m on m.oid = am.member
          where r.rolname = any(?)',
        ['{'.implode(',', [...SecurityBaseline::DEFINER_OWNERS, SecurityBaseline::MIGRATIONS_OWNER]).'}'],
    );

    expect($members)->toBe([]);
})->group('integration', 'schema');

it('grants the provisioner eora_app privileges without SET ROLE', function () {
    $grant = owner()->selectOne(
        "select am.inherit_option, am.set_option from pg_auth_members am
          where am.roleid = 'eora_app'::regrole and am.member = 'eora_provisioner'::regrole",
    );

    expect($grant)->not->toBeNull()
        ->and($grant->inherit_option)->toBeTrue()
        ->and($grant->set_option)->toBeFalse();
})->group('integration', 'schema');

it('lets no runtime role own any object in the database', function () {
    $owned = column(
        "select pg_get_userbyid(owner) || ': ' || kind || ' ' || name from (
            select c.relowner as owner, 'relation' as kind, c.oid::regclass::text as name from pg_class c
            union all select p.proowner, 'function', p.oid::regprocedure::text from pg_proc p
            union all select n.nspowner, 'schema', n.nspname::text from pg_namespace n
            union all select t.typowner, 'type', t.oid::regtype::text from pg_type t
         ) o where pg_get_userbyid(owner) = any(?)",
        ['{'.implode(',', SecurityBaseline::RUNTIME_ROLES).'}'],
    );

    expect($owned)->toBe([]);
})->group('integration', 'schema');

it('hardens runtime roles: no parameter logging and bounded timeouts', function () {
    foreach (SecurityBaseline::RUNTIME_ROLES as $role) {
        $config = column(
            'select unnest(s.setconfig) from pg_db_role_setting s where s.setrole = ?::regrole and s.setdatabase = 0',
            [$role],
        );

        expect($config)->toContain('log_parameter_max_length=0')
            ->toContain('log_parameter_max_length_on_error=0')
            ->toContain('lock_timeout=3s')
            ->toContain('idle_in_transaction_session_timeout=30s');
    }
})->group('integration', 'schema');

it('matches the reviewed SECURITY DEFINER allowlist exactly', function () {
    // Extension-owned functions are excluded (pgcrypto in schema ext has no definers anyway).
    $definers = column(
        "select n.nspname || '.' || p.proname from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where p.prosecdef and n.nspname not in ('pg_catalog', 'information_schema')
            and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
          order by 1",
    );

    expect($definers)->toBe(SecurityBaseline::DEFINER_FUNCTIONS);
})->group('integration', 'schema');

it('requires an empty search_path, a NOLOGIN definer owner and no PUBLIC execute on every definer', function () {
    $offenders = column(
        "select n.nspname || '.' || p.proname from pg_proc p join pg_namespace n on n.oid = p.pronamespace
           join pg_roles o on o.oid = p.proowner
          where p.prosecdef and n.nspname not in ('pg_catalog', 'information_schema')
            and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')
            and (o.rolcanlogin or not coalesce(p.proconfig @> array['search_path=\"\"'], false)
                 or has_function_privilege('public', p.oid, 'EXECUTE'))",
    );

    expect($offenders)->toBe([]);
})->group('integration', 'schema');

it('forces row level security on every table with a tenant_id column', function () {
    $unprotected = column(
        "select c.oid::regclass::text from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where c.relkind in ('r', 'p') and n.nspname = 'public'
            and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'tenant_id' and not a.attisdropped)
            and not (c.relrowsecurity and c.relforcerowsecurity)",
    );

    expect($unprotected)->toBe([]);
})->group('integration', 'schema');

it('gives PUBLIC no privilege on the database or on any EORA schema', function () {
    $public = column(
        "select n.nspname from pg_namespace n, aclexplode(coalesce(n.nspacl, acldefault('n', n.nspowner))) a
          where n.nspname = any(?) and a.grantee = 0",
        ['{'.implode(',', SecurityBaseline::PRIVATE_SCHEMAS).'}'],
    );
    $database = column(
        "select a.privilege_type from pg_database d, aclexplode(coalesce(d.datacl, acldefault('d', d.datdba))) a
          where d.datname = current_database() and a.grantee = 0",
    );

    expect($public)->toBe([])->and($database)->toBe([]);
})->group('integration', 'schema');

it('places the definer-owned schemas under their NOLOGIN owners', function () {
    $owners = owner()->select(
        "select nspname, pg_get_userbyid(nspowner) as owner from pg_namespace
          where nspname in ('auth', 'auth_api', 'prov', 'prov_api', 'outbox_api', 'ctx', 'ctx_api') order by 1",
    );

    expect(collect($owners)->pluck('owner', 'nspname')->all())->toBe([
        'auth' => 'eora_auth_definer',
        'auth_api' => 'eora_auth_definer',
        'ctx' => 'eora_ctx_verifier',
        'ctx_api' => 'eora_ctx_verifier',
        'outbox_api' => 'eora_outbox_definer',
        'prov' => 'eora_prov_definer',
        'prov_api' => 'eora_prov_definer',
    ]);
})->group('integration', 'schema');
