<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use InvalidArgumentException;

/**
 * Identity of a synthetic demo tenant. E-mail addresses live under the reserved `.test` top-level domain (RFC 2606), so
 * no demo account can ever reach a real mailbox: `<role>@<code>.test`.
 */
final readonly class DemoSpec
{
    public function __construct(public string $code = 'demo-stamping', public string $name = 'Demo Stamping')
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $code) !== 1) {
            throw new InvalidArgumentException('The demo tenant code must be 2–40 characters a-z, 0-9 or "-".');
        }
    }

    public function email(string $local): string
    {
        return "{$local}@{$this->code}.test";
    }
}
