<?php

declare(strict_types=1);

namespace App\Platform\Export;

use Closure;
use InvalidArgumentException;

/**
 * One column of an exported list (D-092: every column of the list, with the screen's heading). A screen cell that
 * shows several facts becomes one column per fact, so every cell is typed (doc 08: numbers and dates stay numbers and
 * dates). The value is a field of the QueryDefinition, or computed from the row's fields.
 */
final readonly class ExportColumn
{
    public const string TEXT = 'text';

    public const string INTEGER = 'integer';

    /** Exact decimal string from the database, written as a number. */
    public const string DECIMAL = 'decimal';

    /** `YYYY-MM-DD`, written as a date. */
    public const string DATE = 'date';

    /** An instant, written as a date and time in the tenant's time zone. */
    public const string INSTANT = 'instant';

    /** Yes / No. */
    public const string BOOLEAN = 'boolean';

    /**
     * @param  string|Closure(array<string, mixed>): mixed  $value  field key, or the value from the row's fields
     * @param  array<string, string>  $labels  screen labels of an enum's values (unknown values are written as they are)
     */
    public function __construct(
        public string $header,
        public string|Closure $value,
        public string $type = self::TEXT,
        public array $labels = [],
    ) {
        if (! in_array($type, [self::TEXT, self::INTEGER, self::DECIMAL, self::DATE, self::INSTANT, self::BOOLEAN], true)) {
            throw new InvalidArgumentException("Unknown export column type {$type}.");
        }
        if ($header === '' || mb_strlen($header) > 60) {
            throw new InvalidArgumentException('An export column needs a heading of at most 60 characters.');
        }
    }

    /** @param  array<string, mixed>  $row  the row as the list returns it */
    public function raw(array $row): mixed
    {
        $value = is_string($this->value) ? ($row[$this->value] ?? null) : ($this->value)($row);

        return is_string($value) && $this->labels !== [] ? ($this->labels[$value] ?? $value) : $value;
    }
}
