<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use RuntimeException;

/** The demo tenant code is taken: seeding never touches an existing tenant (it is not a reset). */
final class DemoAlreadySeeded extends RuntimeException
{
    public function __construct(public readonly string $tenantCode)
    {
        parent::__construct("A tenant with code \"{$tenantCode}\" already exists.");
    }
}
