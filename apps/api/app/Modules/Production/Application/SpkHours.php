<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use stdClass;

/** SPK hours (doc 38 §3): good pieces per hour of a routing operation and the hours of one SPK line. */
final class SpkHours
{
    /** @return numeric-string */
    public static function num(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return is_numeric($text) ? $text : '0';
    }

    /** @param numeric-string $value  rounded half away from zero to 3 decimals, trailing zeros dropped */
    public static function round3(string $value): string
    {
        return self::plain(bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.0005' : '0.0005', 3));
    }

    public static function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** Good pieces per running hour: the routing's standard good output (D-130), or 3600 / cycle for a per-piece cycle; null when not calculable. */
    public static function sph(stdClass $op): ?string
    {
        if ($op->sph !== null) {
            return (string) $op->sph;
        }
        $cycle = self::num($op->cycle_seconds);
        if (bccomp($cycle, '0', 6) <= 0) {
            return null;
        }

        return match ($op->cycle_basis) {
            'PER_PIECE' => self::plain(bcdiv('3600', $cycle, 3)),
            // a stroke makes `parts per cycle` pieces
            'PER_STROKE' => $op->parts_per_cycle === null ? null : self::plain(bcdiv(bcmul('3600', self::num($op->parts_per_cycle), 6), $cycle, 3)),
            default => null,
        };
    }

    /**
     * Hours of one line: qty / SPH + setup / 60 + QC sample / 60 (doc 38 §3); null without SPH.
     *
     * @return numeric-string|null
     */
    public static function hours(?string $sph, string $qty, string $setup, string $qc): ?string
    {
        if ($sph === null || ! is_numeric($sph) || bccomp($sph, '0', 6) <= 0 || ! is_numeric($qty) || ! is_numeric($setup) || ! is_numeric($qc)) {
            return null;
        }

        return bcadd(bcdiv($qty, $sph, 6), bcdiv(bcadd($setup, $qc, 6), '60', 6), 6);
    }
}
