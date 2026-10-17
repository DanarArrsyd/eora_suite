<?php

declare(strict_types=1);

namespace App\Platform\Import;

/** Cell checks shared by the templates: each returns a message, or null when the value is fine. */
final class RowRules
{
    public const array YES = ['YES', 'Y', 'TRUE', '1'];

    public const array NO = ['NO', 'N', 'FALSE', '0'];

    public static function required(string $value, string $header): ?string
    {
        return $value === '' ? "{$header} is required." : null;
    }

    public static function maxLength(string $value, int $max, string $header): ?string
    {
        return mb_strlen($value) > $max ? "{$header}: use at most {$max} characters." : null;
    }

    /** @param  list<string>  $allowed */
    public static function oneOf(string $value, array $allowed, string $header): ?string
    {
        return $value !== '' && ! in_array(strtoupper($value), $allowed, true) ? "{$header}: use one of ".implode(', ', $allowed).'.' : null;
    }

    /** YES/NO (also Y/N, TRUE/FALSE, 1/0); empty = the default. */
    public static function flag(string $value, string $header): ?string
    {
        return $value !== '' && ! in_array(strtoupper($value), [...self::YES, ...self::NO], true) ? "{$header}: use YES or NO." : null;
    }

    public static function isYes(string $value, bool $default): bool
    {
        return $value === '' ? $default : in_array(strtoupper($value), self::YES, true);
    }

    /** A positive decimal with at most `$decimals` decimals (empty is allowed; combine with required()). */
    public static function positiveDecimal(string $value, int $decimals, string $header): ?string
    {
        if ($value === '') {
            return null;
        }

        return preg_match('/^\d{1,12}(\.\d{1,'.$decimals.'})?$/', $value) === 1 && (float) $value > 0
            ? null : "{$header}: enter a number above 0 with at most {$decimals} decimals.";
    }

    /**
     * Records `$key` of `$kind` for `$rowNo`; a message when an earlier row of the file already used it.
     *
     * @param  array<string, array<string, int>>  $seen
     */
    public static function once(array &$seen, string $kind, string $key, int $rowNo, string $what): ?string
    {
        if (isset($seen[$kind][$key])) {
            return "{$what} is also on row {$seen[$kind][$key]} of this file.";
        }
        $seen[$kind][$key] = $rowNo;

        return null;
    }

    /**
     * @param  list<?string>  $checks
     * @return list<string>
     */
    public static function collect(array $checks): array
    {
        return array_values(array_filter($checks, static fn (?string $m) => $m !== null));
    }
}
