<?php

declare(strict_types=1);

namespace App\Platform\Query;

/**
 * The SQL of one list request (QueryRunner::build): trusted SQL text with every value bound. The page, the count and
 * any other reader (EXPLAIN checks, exports) share the same FROM/WHERE and the same stable order. `filtered` is
 * `from … where …` over the filter FROM; `from` is the full FROM when it differs (shown-only joins), else null.
 */
final readonly class BuiltQuery
{
    /** @param  list<mixed>  $bindings  values of `filtered`, in order */
    public function __construct(
        public string $select,
        public string $filtered,
        public ?string $from,
        public string $idColumn,
        public string $orderBy,
        public array $bindings,
        public int $pageNumber,
        public int $pageSize,
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
        if ($this->from === null) {
            return "select {$this->select} {$this->filtered} order by {$this->orderBy} limit ? offset ?";
        }

        // materialized: the planner sees the page's few ids and joins the shown-only columns for those rows only
        return "with page as materialized (select {$this->idColumn} as page_id {$this->filtered} order by {$this->orderBy} limit ? offset ?) "
            ."select {$this->select} from {$this->from} join page on page.page_id = {$this->idColumn} order by {$this->orderBy}";
    }

    /** @return list<mixed> */
    public function pageBindings(): array
    {
        return [...$this->bindings, $this->pageSize, ($this->pageNumber - 1) * $this->pageSize];
    }
}
