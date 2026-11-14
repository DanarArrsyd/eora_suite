<?php

declare(strict_types=1);

namespace App\Platform\Console;

use InvalidArgumentException;

/**
 * Text options and arguments of an Artisan command: a given value as it is, a missing one as '' (what a string cast
 * gave before Laravel 13.40 typed them as array|bool|float|int|string|null), anything else an input error.
 */
trait ReadsTextInput
{
    private function textOption(string $key): string
    {
        return self::text($this->option($key), "--{$key}");
    }

    private function textArgument(string $key): string
    {
        return self::text($this->argument($key), $key);
    }

    private static function text(mixed $value, string $name): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => '',
            default => throw new InvalidArgumentException("{$name} takes one text value."),
        };
    }
}
