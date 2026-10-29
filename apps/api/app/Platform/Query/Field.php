<?php

declare(strict_types=1);

namespace App\Platform\Query;

use InvalidArgumentException;

/**
 * One column of a QueryDefinition (ADR-0008): how it is selected, whether it can be sorted, filtered or searched with
 * `q`, and the closed value set of an enum. Only declared fields reach SQL; request keys never become identifiers.
 */
final readonly class Field
{
    public const string STRING = 'string';

    public const string ENUM = 'enum';

    public const string UUID = 'uuid';

    public const string BOOLEAN = 'boolean';

    public const string INSTANT = 'instant';

    public const string INTEGER = 'integer';

    /**
     * @param  string  $sql  SQL expression over the definition's FROM clause (trusted, written in code)
     * @param  list<string>  $options  allowed values of an enum field
     * @param  'prefix'|'contains'|null  $search  how `q` matches this field: `prefix` = an upper-case code that starts with
     *                                            `q` (any case typed; `^@`, served by a `text_pattern_ops` B-tree), `contains`
     *                                            = text containing `q`, case-insensitive (ILIKE: a filter over the tenant's
     *                                            rows — not leakproof, so no index under forced RLS, D-093)
     * @param  string|null  $searchSql  the `q` predicate when the column lives in a joined table, so its own index is used
     *                                  instead of a filter after the join, e.g.
     *                                  `r.item_id = any (array(select x.id from items x where x.code {match}))`;
     *                                  `{match}` becomes the field's `^@` or ILIKE with the bound pattern
     * @param  string|null  $filterSql  the filter predicate when rows match through a related table, e.g.
     *                                  `r.id = any (array(select o.routing_id from ops o where o.work_center_id {in}))`;
     *                                  `{in}` becomes `in (?, …)` with the given values (UUID and enum fields)
     * @param  bool  $listed  false for a field that only filters or searches: it is not selected into the rows
     */
    public function __construct(
        public string $key,
        public string $sql,
        public string $type = self::STRING,
        public bool $sortable = false,
        public bool $filterable = false,
        public array $options = [],
        public ?string $search = null,
        public ?string $searchSql = null,
        public ?string $filterSql = null,
        public bool $listed = true,
    ) {
        if (preg_match('/^[a-z][A-Za-z0-9]{0,39}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid field key {$key}.");
        }
        if (! in_array($type, [self::STRING, self::ENUM, self::UUID, self::BOOLEAN, self::INSTANT, self::INTEGER], true)) {
            throw new InvalidArgumentException("Unknown field type {$type}.");
        }
        if ($searchSql !== null && ($search === null || substr_count($searchSql, '{match}') !== 1)) {
            throw new InvalidArgumentException('A search predicate needs a search mode and exactly one {match}.');
        }
        if ($filterSql !== null && (! $filterable || ! in_array($type, [self::UUID, self::ENUM], true) || substr_count($filterSql, '{in}') !== 1)) {
            throw new InvalidArgumentException('A filter predicate belongs to a filterable UUID or enum field and has exactly one {in}.');
        }
        if (! $listed && ($sortable || ($filterSql === null && $searchSql === null))) {
            throw new InvalidArgumentException('A field that is not listed only filters or searches through its own predicate.');
        }
        if (($type === self::ENUM) !== ($options !== [])) {
            throw new InvalidArgumentException('Enum fields, and only enum fields, list their options.');
        }
    }

    /** @return array<string, mixed> the field as published by GET /query/{resource}/schema */
    public function schema(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'searchable' => $this->search !== null,
            'options' => $this->options === [] ? null : $this->options,
        ], static fn ($v) => $v !== null);
    }
}
