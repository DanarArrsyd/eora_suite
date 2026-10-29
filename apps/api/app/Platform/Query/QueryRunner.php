<?php

declare(strict_types=1);

namespace App\Platform\Query;

use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Runs a QueryDefinition for the doc 24 §3.1 grammar in offset mode (master data, §3.3):
 * `filter[k]=v`, `filter[k][]=a&filter[k][]=b`, `q=…`, `sort=-a,b`, `page[number]=`, `page[size]=`.
 * Unknown filter keys → 422 FILTER_NOT_ALLOWED; bad values or sort keys → 422 VALIDATION_FAILED; a page size above
 * the resource maximum is clamped and reported in `meta.page.size`. Every value is a bound parameter.
 */
final class QueryRunner
{
    private const int DEFAULT_PAGE_SIZE = 50;

    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  array<string, mixed>  $input  the request query (already decoded)
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(EoraPostgresConnection $db, QueryDefinition $definition, array $input): array
    {
        $query = $this->build($db, $definition, $input);
        $total = (int) $db->selectOne($query->countSql(), $query->bindings)->n;
        $rows = $db->select($query->pageSql(), $query->pageBindings());
        $tenant = $db->selectOne('select t.timezone from tenants t where t.id = (select c.tenant from ctx_api.context() c)');

        return [
            'data' => array_values(array_map($definition->row(...), $rows)),
            'meta' => [
                'total' => $total,
                'page' => ['number' => $query->pageNumber, 'size' => $query->pageSize],
                'asOf' => Date::now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'timezone' => (string) ($tenant->timezone ?? 'Asia/Jakarta'),
            ],
        ];
    }

    /**
     * The SQL of $input against $definition for the caller (view permission and its scopes applied), without running
     * it: the list, the EXPLAIN checks and exports read exactly the same rows in the same order.
     *
     * @param  array<string, mixed>  $input  the request query (already decoded)
     */
    public function build(EoraPostgresConnection $db, QueryDefinition $definition, array $input): BuiltQuery
    {
        $scopes = $this->authorizer->requireAnywhere($db, $definition->permission());
        $fields = [];
        foreach ($definition->fields() as $field) {
            $fields[$field->key] = $field;
        }

        [$where, $bindings] = $definition->scopePredicate($scopes);
        $clauses = [$where];
        foreach ($this->filters($input['filter'] ?? [], $fields) as [$sql, $values]) {
            $clauses[] = $sql;
            array_push($bindings, ...$values);
        }
        $q = isset($input['q']) && is_string($input['q']) ? trim($input['q']) : '';
        if ($q !== '') {
            [$sql, $values] = $this->search($q, $fields);
            $clauses[] = $sql;
            array_push($bindings, ...$values);
        }

        $page = $input['page'] ?? [];
        $number = max(1, $this->integer(is_array($page) ? ($page['number'] ?? 1) : 1, 'page.number'));
        $size = min($definition->maxPageSize(), max(1, $this->integer(is_array($page) ? ($page['size'] ?? self::DEFAULT_PAGE_SIZE) : self::DEFAULT_PAGE_SIZE, 'page.size')));

        $where = implode(' and ', $clauses);

        return new BuiltQuery(
            select: self::select($fields),
            filtered: 'from '.($definition->filterFrom() ?? $definition->from()).' where '.$where,
            where: $where,
            from: $definition->from(),
            paged: $definition->filterFrom() !== null,
            idColumn: $definition->idColumn(),
            orderBy: $this->orderBy($input['sort'] ?? null, $fields, $definition),
            bindings: $bindings,
            pageNumber: $number,
            pageSize: $size,
            scopes: $scopes,
        );
    }

    /**
     * One row of $definition by id, in the list representation — the response of a command that already authorized
     * the caller for the record it wrote. Null when the row is not visible (RLS).
     *
     * @return array<string, mixed>|null
     */
    public function find(EoraPostgresConnection $db, QueryDefinition $definition, string $id): ?array
    {
        $select = self::select($definition->fields());
        $row = $db->selectOne("select {$select} from {$definition->from()} where {$definition->idColumn()} = ?::uuid", [$id]);

        return $row === null ? null : $definition->row($row);
    }

    /**
     * @param  array<string, Field>  $fields
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function filters(mixed $input, array $fields): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages(['filter' => 'Filters are given as filter[key]=value.']);
        }
        $out = [];
        foreach ($input as $key => $value) {
            $field = $fields[(string) $key] ?? null;
            if ($field === null || ! $field->filterable) {
                throw new DomainProblem(422, 'FILTER_NOT_ALLOWED', 'Filter not allowed', "This list cannot be filtered by \"{$key}\".");
            }
            $values = array_values(array_filter(is_array($value) ? $value : [$value], static fn ($v) => is_string($v) && $v !== ''));
            if ($values === []) {
                continue;
            }
            $out[] = match ($field->type) {
                Field::ENUM => $this->inList($field, $values, static fn (string $v) => in_array($v, $field->options, true)),
                Field::UUID => $this->inList($field, array_map('strtolower', $values), static fn (string $v) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1),
                Field::BOOLEAN => count($values) === 1 && in_array($values[0], ['true', 'false'], true)
                    ? ["{$field->sql} = ?", [$values[0] === 'true']]
                    : throw ValidationException::withMessages(["filter.{$field->key}" => 'Use true or false.']),
                default => ["{$field->sql} ilike ? escape '\\'", [self::like($values[0]).'%']],
            };
        }

        return $out;
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: list<string>}
     */
    private function inList(Field $field, array $values, callable $valid): array
    {
        foreach ($values as $v) {
            if (! $valid($v)) {
                throw ValidationException::withMessages(["filter.{$field->key}" => 'Unknown value.']);
            }
        }
        $cast = $field->type === Field::UUID ? '::uuid' : '';
        $in = 'in ('.implode(', ', array_fill(0, count($values), "?{$cast}")).')';

        return [$field->filterSql !== null ? str_replace('{in}', $in, $field->filterSql) : "{$field->sql} {$in}", $values];
    }

    /** @param  array<array-key, Field>  $fields */
    private static function select(array $fields): string
    {
        $listed = array_filter($fields, static fn (Field $f) => $f->listed);

        return implode(', ', array_map(static fn (Field $f) => "{$f->sql} as \"{$f->key}\"", $listed));
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array{0: string, 1: list<string>}
     */
    private function search(string $q, array $fields): array
    {
        if (mb_strlen($q) > 100) {
            throw ValidationException::withMessages(['q' => 'Search is limited to 100 characters.']);
        }
        $parts = $bindings = [];
        foreach ($fields as $field) {
            if ($field->search === null) {
                continue;
            }
            // Codes are stored upper case (CHECK): a prefix is `^@` (starts_with) on the upper-cased input — leakproof, so
            // under forced RLS it can still drive a text_pattern_ops B-tree; LIKE/ILIKE are not leakproof and are
            // evaluated as a filter after the tenant's rows are found.
            [$match, $pattern] = $field->search === 'prefix'
                ? ['^@ ?', mb_strtoupper($q)]
                : ["ilike ? escape '\\'", '%'.self::like($q).'%'];
            $parts[] = $field->searchSql !== null ? str_replace('{match}', $match, $field->searchSql) : "{$field->sql} {$match}";
            $bindings[] = $pattern;
        }

        return $parts === [] ? ['true', []] : ['('.implode(' or ', $parts).')', $bindings];
    }

    /** @param  array<string, Field>  $fields */
    private function orderBy(mixed $sort, array $fields, QueryDefinition $definition): string
    {
        $rules = [];
        if (is_string($sort) && trim($sort) !== '') {
            foreach (explode(',', $sort) as $part) {
                $desc = str_starts_with($part, '-');
                $key = ltrim(trim($part), '-');
                $field = $fields[$key] ?? null;
                if ($field === null || ! $field->sortable) {
                    throw ValidationException::withMessages(['sort' => "This list cannot be sorted by \"{$key}\"."]);
                }
                $rules[] = [$field, $desc];
            }
        } else {
            foreach ($definition->defaultSort() as [$key, $desc]) {
                $rules[] = [$fields[$key], $desc];
            }
        }
        $order = array_map(static fn (array $r) => $r[0]->sql.($r[1] ? ' desc' : ' asc').' nulls last', $rules);
        $order[] = $definition->idColumn().' asc';

        return implode(', ', $order);
    }

    private function integer(mixed $value, string $key): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1)) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$key => 'Must be a positive whole number.']);
    }

    /** Escapes LIKE wildcards so `q` and text filters match literally. */
    private static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
