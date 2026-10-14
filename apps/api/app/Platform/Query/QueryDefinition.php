<?php

declare(strict_types=1);

namespace App\Platform\Query;

use App\Platform\Access\Scope;
use stdClass;

/**
 * Declarative list resource (ADR-0008, doc 24 §3): grid, schema and (later) export run the same definition, so they
 * can never disagree. Row scope is applied here, never by callers: tenant isolation by RLS, data scope by the
 * caller's view-permission scopes over the declared plant/company columns. No mutations.
 */
abstract class QueryDefinition
{
    /** Resource name in the URL, e.g. `core.plants`. */
    abstract public function resource(): string;

    /** View permission; its scopes limit the rows. */
    abstract public function permission(): string;

    /** FROM clause (with joins) in trusted SQL; every table is tenant-owned and under forced RLS. */
    abstract public function from(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    /** Unique row id expression, appended to every sort as the stable tiebreaker. */
    abstract public function idColumn(): string;

    /**
     * Default sort as `[fieldKey, descending]` pairs.
     *
     * @return list<array{0: string, 1: bool}>
     */
    abstract public function defaultSort(): array;

    public function maxPageSize(): int
    {
        return 200;
    }

    /** Plant id expression of a row, for plant-bound resources; null = not plant-bound. */
    public function plantColumn(): ?string
    {
        return null;
    }

    /** Company id expression of a row (for a plant-bound row: its plant's company); null = not company-bound. */
    public function companyColumn(): ?string
    {
        return null;
    }

    /**
     * SQL predicate limiting rows to $scopes: the tenant scope sees everything; a company scope its company (and, for
     * plant-bound rows, that company's plants); a plant scope only its plant. A resource bound to neither plant nor
     * company is visible tenant-wide to anyone holding the view permission.
     *
     * @param  list<Scope>  $scopes
     * @return array{0: string, 1: list<string>}
     */
    final public function scopePredicate(array $scopes): array
    {
        $plant = $this->plantColumn();
        $company = $this->companyColumn();
        if ($plant === null && $company === null) {
            return ['true', []];
        }
        $companies = $plants = [];
        foreach ($scopes as $scope) {
            if ($scope->type === Scope::TENANT) {
                return ['true', []];
            }
            if ($scope->type === Scope::COMPANY) {
                $companies[] = (string) $scope->companyId;
            } else {
                $plants[] = (string) $scope->plantId;
            }
        }
        $clauses = $bindings = [];
        if ($company !== null && $companies !== []) {
            $clauses[] = "{$company} = any(?::uuid[])";
            $bindings[] = self::uuidArray($companies);
        }
        if ($plant !== null && $plants !== []) {
            $clauses[] = "{$plant} = any(?::uuid[])";
            $bindings[] = self::uuidArray($plants);
        }

        return $clauses === [] ? ['false', []] : ['('.implode(' or ', $clauses).')', $bindings];
    }

    /**
     * Shapes one database row for the response (decimal and date formatting stay server-side, doc 24 §3.2).
     *
     * @return array<string, mixed>
     */
    public function row(stdClass $row): array
    {
        return (array) $row;
    }

    /** @param  list<string>  $ids */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', array_unique($ids)).'}';
    }
}
