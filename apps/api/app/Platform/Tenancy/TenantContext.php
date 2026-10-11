<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use InvalidArgumentException;

/**
 * Who acts, and for which tenant, inside one database transaction. Built from the server session or the job
 * payload — never from client input (DD-07). A null tenant is the tenant-less context of the tenant picker.
 */
final readonly class TenantContext
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(public ?string $tenantId, public string $actorId)
    {
        if ($tenantId !== null && preg_match(self::UUID, $tenantId) !== 1) {
            throw new InvalidArgumentException('Tenant id must be a lowercase UUID.');
        }

        if (preg_match(self::UUID, $actorId) !== 1) {
            throw new InvalidArgumentException('Actor id must be a lowercase UUID.');
        }
    }

    public static function forTenant(string $tenantId, string $actorId): self
    {
        return new self($tenantId, $actorId);
    }

    public static function tenantless(string $actorId): self
    {
        return new self(null, $actorId);
    }
}
