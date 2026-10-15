<?php

declare(strict_types=1);

use Tests\Support\AccessFixtures;

/* Helpers shared by the master-data feature tests (doc 29). */

/** @return array<string, mixed> */
function orgUser(object $test, array $t, string $role, array $scope = ['TENANT']): array
{
    $u = member($test, $t);
    AccessFixtures::grant($t, $u['id'], $role, $scope);
    signIn($test, $u, $t);

    return $u;
}

function patchWith(object $test, string $uri, array $body, ?int $version)
{
    return spa($test)->patchJson($uri, $body, $version === null ? [] : ['If-Match' => '"'.$version.'"']);
}
