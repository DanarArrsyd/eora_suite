<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use RuntimeException;

/** An interrupted demo seed already activated accounts: finishing it needs the password of that run, not a new one. */
final class DemoResumeNeedsPassword extends RuntimeException
{
    public function __construct(public readonly string $tenantCode)
    {
        parent::__construct("The demo tenant \"{$tenantCode}\" was only partly seeded and some accounts already have the demo password.");
    }
}
