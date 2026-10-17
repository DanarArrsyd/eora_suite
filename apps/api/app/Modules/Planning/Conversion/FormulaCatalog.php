<?php

declare(strict_types=1);

namespace App\Modules\Planning\Conversion;

/**
 * Conversion formulas and their inputs (doc 02 "Theoretical pcs conversion", ADR-0005, D-080). Each input carries a fixed
 * unit; a value without it, or in another unit, is refused — never guessed. Only the inputs are stored and validated in
 * M2; the calculation itself (T, GYV, PvS, UMV) is B8. Kept in step with the database CHECK `eora_conversion_inputs_ok`
 * (migration 001500); a changed formula gets a new code (doc 30).
 */
final class FormulaCatalog
{
    /** Value rule: > 0, ≥ 0, whole number ≥ 1, whole number ≥ 0. */
    public const string POSITIVE = 'pos';

    public const string NON_NEGATIVE = 'zero';

    public const string COUNT = 'int1';

    public const string COUNT_OR_ZERO = 'int0';

    /** Material geometry copied from the input item's material spec when a profile's first version is drafted. */
    public const array SPEC_INPUTS = ['thickness_mm' => 'thickness_mm', 'width_mm' => 'width_mm', 'density_g_cm3' => 'density_g_cm3'];

    /** @var array<string, array{form: string, label: string, inputs: array<string, array{unit: string, rule: string, label: string}>}> */
    public const array FORMULAS = [
        'SHEET_V1' => [
            'form' => 'SHEET',
            'label' => 'Sheet: sheets × blanks per sheet × parts per blank',
            'inputs' => [
                'thickness_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Thickness'],
                'width_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Sheet width'],
                'length_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Sheet length'],
                'density_g_cm3' => ['unit' => 'g/cm3', 'rule' => self::POSITIVE, 'label' => 'Density'],
                'blanks_per_sheet' => ['unit' => 'blank/sheet', 'rule' => self::COUNT, 'label' => 'Usable blanks per sheet'],
                'parts_per_blank' => ['unit' => 'pc/blank', 'rule' => self::COUNT, 'label' => 'Parts per blank'],
                'setup_sheets' => ['unit' => 'sheet', 'rule' => self::COUNT_OR_ZERO, 'label' => 'Setup sheets per run'],
            ],
        ],
        'COIL_V1' => [
            'form' => 'COIL',
            'label' => 'Coil: usable length ÷ pitch × parts per stroke',
            'inputs' => [
                'thickness_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Thickness'],
                'width_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Strip width'],
                'density_g_cm3' => ['unit' => 'g/cm3', 'rule' => self::POSITIVE, 'label' => 'Density'],
                'pitch_mm' => ['unit' => 'mm', 'rule' => self::POSITIVE, 'label' => 'Pitch'],
                'parts_per_stroke' => ['unit' => 'pc/stroke', 'rule' => self::COUNT, 'label' => 'Parts per stroke (cavities)'],
                'head_tail_mm' => ['unit' => 'mm', 'rule' => self::NON_NEGATIVE, 'label' => 'Head + tail allowance'],
                'setup_mm' => ['unit' => 'mm', 'rule' => self::NON_NEGATIVE, 'label' => 'Setup allowance per run'],
            ],
        ],
    ];

    /** The single current formula of a material form. */
    public static function forForm(string $form): string
    {
        return $form === 'SHEET' ? 'SHEET_V1' : 'COIL_V1';
    }

    /**
     * Problems with `$inputs` for `$formula` keyed by input (empty = fine). Values are decimal strings or numbers with at
     * most 6 decimals; `$complete` also requires every input.
     *
     * @param  array<array-key, mixed>  $inputs
     * @return array<string, string>
     */
    public static function problems(string $formula, array $inputs, bool $complete): array
    {
        $spec = self::FORMULAS[$formula]['inputs'];
        $problems = [];
        foreach ($inputs as $key => $entry) {
            $key = (string) $key;
            if (! isset($spec[$key])) {
                $problems[$key] = 'Not an input of this formula.';

                continue;
            }
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;
            $unit = is_array($entry) ? ($entry['unit'] ?? null) : null;
            if ($unit !== $spec[$key]['unit']) {
                $problems[$key] = sprintf('Give the value in %s.', $spec[$key]['unit']);

                continue;
            }
            if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || preg_match('/^(0|[1-9]\d{0,11})(\.\d{1,6})?$/', (string) $value) !== 1) {
                $problems[$key] = 'Enter a number with at most 6 decimals.';

                continue;
            }
            $number = (string) $value;
            $whole = ! str_contains($number, '.') || rtrim(substr($number, strpos($number, '.') + 1), '0') === '';
            $zero = (float) $number === 0.0;
            $problems += match ($spec[$key]['rule']) {
                self::POSITIVE => $zero ? [$key => 'Must be more than 0.'] : [],
                self::COUNT => ! $whole || $zero ? [$key => 'Must be a whole number of at least 1.'] : [],
                self::COUNT_OR_ZERO => ! $whole ? [$key => 'Must be a whole number.'] : [],
                default => [],
            };
        }
        if ($complete) {
            foreach (array_diff_key($spec, $inputs) as $key => $field) {
                $problems[$key] = sprintf('%s is required.', $field['label']);
            }
        }

        return $problems;
    }

    /**
     * JSON text for the `inputs` column with each value as an exact JSON number (never through a PHP float).
     *
     * @param  array<string, array{value: int|float|string, unit: string}>  $inputs  already checked by problems()
     */
    public static function toJson(array $inputs): string
    {
        $parts = [];
        foreach ($inputs as $key => $entry) {
            $parts[] = sprintf('%s:{"value":%s,"unit":%s}', json_encode($key), (string) $entry['value'], json_encode($entry['unit']));
        }

        return '{'.implode(',', $parts).'}';
    }
}
