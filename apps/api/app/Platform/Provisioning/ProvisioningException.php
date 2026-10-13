<?php

declare(strict_types=1);

namespace App\Platform\Provisioning;

use RuntimeException;

/** A provisioning run that cannot continue for a known reason (e.g. TENANT_CODE_TAKEN); safe to show the operator. */
final class ProvisioningException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
