<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use DateTimeZone;
use InvalidArgumentException;

/**
 * What the operator provides to create a tenant (doc 25 §2.5). Validated here so the database never sees a value its
 * CHECK constraints would reject halfway through the tenant transaction.
 */
final readonly class TenantSpec
{
    public function __construct(
        public string $tenantCode,
        public string $tenantName,
        public string $adminEmail,
        public string $companyCode,
        public string $companyName,
        public string $plantCode,
        public string $plantName,
        public string $productionDayStartsAt,
        public string $timezone = 'Asia/Jakarta',
    ) {
        $checks = [
            'tenant code (a-z, 0-9, -; 2–40)' => preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $tenantCode) === 1,
            'tenant name (1–200)' => self::length($tenantName, 200),
            'admin e-mail' => preg_match('/^\s*[^@\s]{1,64}@[^@\s]{1,253}\.[^@\s]{2,63}\s*$/', $adminEmail) === 1 && strlen($adminEmail) <= 254,
            'company code (A-Z, 0-9, _ -; 1–20)' => preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $companyCode) === 1,
            'company name (1–200)' => self::length($companyName, 200),
            'plant code (A-Z, 0-9, _ -; 1–20)' => preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $plantCode) === 1,
            'plant name (1–200)' => self::length($plantName, 200),
            'production day start (HH:MM)' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $productionDayStartsAt) === 1,
            'time zone (IANA)' => in_array($timezone, DateTimeZone::listIdentifiers(), true),
        ];
        $invalid = array_keys(array_filter($checks, static fn (bool $ok) => ! $ok));

        if ($invalid !== []) {
            throw new InvalidArgumentException('Invalid '.implode(', ', $invalid).'.');
        }
    }

    private static function length(string $value, int $max): bool
    {
        $n = mb_strlen(trim($value));

        return $n >= 1 && $n <= $max;
    }
}
