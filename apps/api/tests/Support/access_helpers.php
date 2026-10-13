<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Tests\Support\AccessFixtures;
use Tests\TestCase;

/*
 * Shared helpers for the RBAC and member-administration feature tests (doc 25 §11 groups B/C).
 */

/** Signs $u in and selects $tenant. */
function signIn(TestCase $test, array $u, array $tenant): void
{
    spa($test)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($test)->postJson('/api/v1/auth/tenant', ['tenantId' => $tenant['tenant']])->assertOk();
}

/** An accepted member of $tenant with its ids. */
function member(TestCase $test, array $tenant): array
{
    $u = activeUser($test, $tenant);
    $u['id'] = AccessFixtures::userId($tenant, $u['email']);
    $u['membership'] = AccessFixtures::membershipId($tenant, $u['id']);

    return $u;
}

/** Runs $work as eora_app inside $tenant with $actor as the signed actor. */
function asApp(array $tenant, string $actor, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($tenant['tenant'], $actor), $work);
}

function roleId(array $tenant, string $code): string
{
    return (string) AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->selectOne('select id from roles where code = ?', [$code])->id);
}

/** @return list<string> */
function eventActions(array $tenant): array
{
    return array_column(AccessFixtures::run($tenant, fn (EoraPostgresConnection $db) => $db->select(
        "select action from security_events where source = 'app' and action like 'access.%' order by occurred_at",
    )), 'action');
}

/** Body for POST /core/role-assignments. */
function grantBody(array $tenant, string $membership, string $role, array $scope = ['TENANT']): array
{
    return array_filter([
        'membershipId' => $membership,
        'roleId' => roleId($tenant, $role),
        'scopeType' => $scope[0],
        'companyId' => $scope[0] === 'COMPANY' ? $scope[1] : null,
        'plantId' => $scope[0] === 'PLANT' ? $scope[1] : null,
        'reason' => 'onboarding',
    ]);
}
