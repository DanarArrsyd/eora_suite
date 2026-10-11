<?php

declare(strict_types=1);

namespace App\Platform\Security;

use RuntimeException;

/**
 * Keyed hashes for correlatable but non-reversible identifiers in security events (ip_hash, user_agent_hash,
 * email_hmac): HMAC-SHA256 with the server pepper (D-B1-22). The raw value is never stored.
 */
final class SecurityHasher
{
    private readonly string $key;

    public function __construct(string $hexKey)
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hexKey) !== 1) {
            throw new RuntimeException('EORA_PEPPER_KEY must be 64 lowercase hex characters.');
        }

        $this->key = (string) hex2bin($hexKey);
    }

    public function hash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, $this->key);
    }
}
