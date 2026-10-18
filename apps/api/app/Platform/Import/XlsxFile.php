<?php

declare(strict_types=1);

namespace App\Platform\Import;

use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streaming XLSX reading and writing with OpenSpout (ADR-0017): only the first sheet is read; every cell becomes trimmed
 * text, so templates validate one representation. Written cells that would start a formula are prefixed with an
 * apostrophe (doc 08: escape spreadsheet formula injection).
 */
final class XlsxFile
{
    /**
     * Rows of the first sheet as `[excel row number, list<string>]`, empty rows skipped.
     *
     * @return Generator<int, array{0: int, 1: list<string>}>
     */
    public static function rows(string $path): Generator
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $values = self::texts($row);
                    if (implode('', $values) !== '') {
                        yield [$number, $values];
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Writes a sheet: a bold header row, then the data rows.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string>>  $rows
     */
    public static function write(string $path, array $header, iterable $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle(array_map(self::safe(...), $header), (new Style)->withFontBold(true)));
        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues(array_map(self::safe(...), $values)));
        }
        $writer->close();
    }

    /** @return list<string> */
    private static function texts(Row $row): array
    {
        $values = [];
        $last = $row->cells === [] ? -1 : max(array_keys($row->cells));
        for ($i = 0; $i <= $last; $i++) {
            $values[] = isset($row->cells[$i]) ? trim(self::text($row->cells[$i])) : '';
        }

        return $values;
    }

    private static function text(Cell $cell): string
    {
        $value = match (true) {
            $cell instanceof Cell\TextRunCell => $cell->getStringValue(),
            $cell instanceof Cell\FormulaCell => $cell->getComputedValue(),
            default => $cell->getValue(),
        };

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            // whole numbers typed in Excel arrive as floats; the shortest round-trip text otherwise
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof DateInterval => $value->format('%H:%I:%S'),
            is_string($value) => $value,
            default => '',
        };
    }

    private static function safe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
