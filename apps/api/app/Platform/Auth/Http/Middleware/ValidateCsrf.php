<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * CSRF protection for stateful (cookie) API requests (ADR-0006). Unlike the framework default it is never switched off
 * under PHPUnit, so the test suite proves the protection instead of bypassing it.
 */
final class ValidateCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
