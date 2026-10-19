<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

/** Suppliers (doc 29 §1.6). */
final class SuppliersTemplate extends PartnersTemplate
{
    public function code(): string
    {
        return 'suppliers';
    }

    public function title(): string
    {
        return 'Suppliers';
    }

    protected function table(): string
    {
        return 'suppliers';
    }

    protected function entity(): string
    {
        return 'supplier';
    }
}
