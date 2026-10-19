<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

/** Customers (doc 29 §1.6). */
final class CustomersTemplate extends PartnersTemplate
{
    public function code(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return 'Customers';
    }

    protected function table(): string
    {
        return 'customers';
    }

    protected function entity(): string
    {
        return 'customer';
    }
}
