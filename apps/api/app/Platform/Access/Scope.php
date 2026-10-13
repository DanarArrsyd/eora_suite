<?php

declare(strict_types=1);

namespace App\Platform\Access;

use InvalidArgumentException;

/**
 * A data scope inside the active tenant (doc 18, D-B1-02): the whole tenant, one company, or one plant. A plant scope
 * carries its company so coverage can be decided without another lookup. Warehouse and work-center scopes arrive
 * with B2.
 */
final readonly class Scope
{
    public const string TENANT = 'TENANT';

    public const string COMPANY = 'COMPANY';

    public const string PLANT = 'PLANT';

    private function __construct(public string $type, public ?string $companyId, public ?string $plantId) {}

    public static function tenant(): self
    {
        return new self(self::TENANT, null, null);
    }

    public static function company(string $companyId): self
    {
        return new self(self::COMPANY, $companyId, null);
    }

    /** $companyId is the plant's company (plants.company_id), used only for coverage by a company scope. */
    public static function plant(string $plantId, string $companyId): self
    {
        return new self(self::PLANT, $companyId, $plantId);
    }

    public static function of(string $type, ?string $companyId, ?string $plantId, ?string $plantCompanyId): self
    {
        return match ($type) {
            self::TENANT => self::tenant(),
            self::COMPANY => self::company($companyId ?? throw new InvalidArgumentException('Company scope without company.')),
            self::PLANT => self::plant($plantId ?? throw new InvalidArgumentException('Plant scope without plant.'), $plantCompanyId ?? throw new InvalidArgumentException('Plant scope without company.')),
            default => throw new InvalidArgumentException('Unknown scope type.'),
        };
    }

    /** Grantor ⊇ grantee for scope (doc 25 §13): tenant ⊇ company ⊇ its plants; a plant covers only itself. */
    public function covers(self $target): bool
    {
        return match ($this->type) {
            self::TENANT => true,
            self::COMPANY => $target->type !== self::TENANT && $target->companyId === $this->companyId,
            default => $target->type === self::PLANT && $target->plantId === $this->plantId,
        };
    }
}
