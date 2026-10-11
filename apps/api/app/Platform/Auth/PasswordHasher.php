<?php

declare(strict_types=1);

namespace App\Platform\Auth;

/**
 * argon2id with the D-B1-07 parameters (m = 64 MiB, t = 3, p = 1). Verification happens in PHP (D-B1-23b a): the
 * database never sees a plaintext password. When no stored hash exists a fixed dummy hash is verified instead, so
 * unknown, locked and disabled accounts cost the same time as a wrong password.
 */
final class PasswordHasher
{
    private const array OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];

    private ?string $dummy = null;

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $password, ?string $hash): bool
    {
        if ($hash === null) {
            password_verify($password, $this->dummy ??= $this->hash(bin2hex(random_bytes(16))));

            return false;
        }

        return password_verify($password, $hash);
    }
}
