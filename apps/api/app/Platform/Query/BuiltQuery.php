<?php

declare(strict_types=1);

namespace App\Platform\Query;

use App\Platform\Access\Scope;

/**
 * The SQL of one list request (QueryRunner::build): trusted SQL text with every value bound. The page, the count and
 * any other reader (EXPLAIN checks, exports) share the same FROM/WHERE and the same stable order. `filtered` is
 * `from … where …` over the filter FROM; `from` is the full FROM, `paged` says the filter FROM differs (shown-only
 * joins), so pages pick their ids first.
 */
final readonly class BuiltQuery
{
    /**
     * @param  list<mixed>  $bindings  values of `filtered`, in order
     * @param  list<Scope>  $scopes  the caller's scopes of the view permission the rows are limited to
     */
    public function __construct(
        public string $select,
        public string $filtered,
        public string $where,
        public string $from,
        public bool $paged,
        public string $idColumn,
        public string $orderBy,
        public array $bindings,
        public int $pageNumber,
        public int $pageSize,
        public array $scopes = [],
    ) {}

    public function countSql(): string
    {
        return "select count(*) as n {$this->filtered}";
    }

    /**
     * The requested page; bindings: pageBindings(). With a filter FROM (QueryDefinition::filterFrom) the page's ids are
     * chosen there first, and only those rows are read with the full FROM.
     */
    public function pageSql(): string
    {
        if (! $this->paged) {
            return "select {$this->select} {$this->filtered} order by {$this->orderBy} limit ? offset ?";
        }

        // materialized: the planner sees the page's few ids and joins the shown-only columns for those rows only
        return "with page as materialized (select {$this->idColumn} as page_id {$this->filtered} order by {$this->orderBy} limit ? offset ?) "
            ."select {$this->select} from {$this->from} join page on page.page_id = {$this->idColumn} order by {$this->orderBy}";
    }

    /** Every matching id in list order (bindings: `bindings`) — the rows of an export, fixed at one snapshot. */
    public function idsSql(): string
    {
        return "select {$this->idColumn} as id {$this->filtered} order by {$this->orderBy}";
    }

    /**
     * The rows of a set of ids that still match the request — scope, filters and search applied again, so a row that
     * moved out of the caller's scope since the ids were fixed is not read. Bindings: rowsByIdBindings(). Any order:
     * the caller puts them back in list order.
     */
    public function rowsByIdSql(): string
    {
        return "select {$this->idColumn} as row_id, {$this->select} from {$this->from} where {$this->where} and {$this->idColumn} = any(?::uuid[])";
    }

    /**
     * @param  list<string>  $ids
     * @return list<mixed>
     */
    public function rowsByIdBindings(array $ids): array
    {
        return [...$this->bindings, '{'.implode(',', $ids).'}'];
    }

    /** @return list<mixed> */
    public function pageBindings(): array
    {
        return [...$this->bindings, $this->pageSize, ($this->pageNumber - 1) * $this->pageSize];
    }
}
