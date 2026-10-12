<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * Password rule (D-B1-07): at least 12 characters and not on the offline breached/common-password list
 * (resources/security/breached-passwords.txt, compared case-insensitively). No composition rules, no online lookups.
 */
final class PasswordPolicy implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $breached = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 12) {
            $fail('The password must be at least 12 characters.');

            return;
        }

        if (isset(self::breached()[mb_strtolower($value)])) {
            $fail('This password is too common or appeared in a data breach. Choose another one.');
        }
    }

    /** @return array<string, true> */
    private static function breached(): array
    {
        if (self::$breached === null) {
            self::$breached = [];
            $lines = @file(resource_path('security/breached-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException('breached-password list missing'); // fail closed: never accept unchecked passwords
            }
            foreach ($lines as $line) {
                if (! str_starts_with($line, '#')) {
                    self::$breached[mb_strtolower(trim($line))] = true;
                }
            }
        }

        return self::$breached;
    }
}
