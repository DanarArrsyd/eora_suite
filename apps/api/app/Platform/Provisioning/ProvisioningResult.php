<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

/** Outcome of one provisioning run: the tenant, its first administrator's membership, and whether this run created it. */
final readonly class ProvisioningResult
{
    public function __construct(public string $tenantId, public string $adminMembershipId, public bool $created) {}
}
