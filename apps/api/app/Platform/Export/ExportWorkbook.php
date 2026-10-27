<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Import\XlsxFile;
use DateTimeImmutable;
use DateTimeZone;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The exported workbook (doc 08 §Branding, D-092): a data sheet — bold headings, frozen heading row, auto-filter,
 * typed cells (numbers, dates and times in the tenant's time zone), text that would start a formula escaped — and an
 * About sheet with the list, its search, filters and sort, the row count, the requester and the EORA footer.
 * Rows are written as they come, so the file never sits in memory.
 */
final class ExportWorkbook
{
    /**
     * @param  list<ExportColumn>  $columns
     * @param  iterable<array<string, mixed>>  $rows  list rows in list order
     * @param  list<array{0: string, 1: string}>  $about  label, value
     * @return int rows written
     */
    public static function write(string $path, string $title, array $columns, iterable $rows, array $about, DateTimeZone $timezone): int
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(self::sheetName($title));
        $sheet->setSheetView((new SheetView)->withFreezeRow(2));
        foreach ($columns as $i => $column) {
            $sheet->setColumnWidth(self::width($column), $i + 1);
        }

        $bold = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle(array_map(static fn (ExportColumn $c) => XlsxFile::safe($c->header), $columns), $bold));
        $styles = [];
        foreach ($columns as $i => $column) {
            $format = match ($column->type) {
                ExportColumn::DATE => 'yyyy-mm-dd',
                ExportColumn::INSTANT => 'yyyy-mm-dd hh:mm',
                default => null,
            };
            if ($format !== null) {
                $styles[$i] = (new Style)->withFormat($format);
            }
        }

        $count = 0;
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValuesWithStyles(array_map(static fn (ExportColumn $c) => self::cell($c, $row, $timezone), $columns), $styles));
            $count++;
        }
        $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, count($columns) - 1), $count + 1));

        $writer->addNewSheetAndMakeItCurrent()->setName('About');
        $writer->getCurrentSheet()->setColumnWidth(18, 1);
        $writer->getCurrentSheet()->setColumnWidth(80, 2);
        foreach ($about as [$label, $value]) {
            $writer->addRow(Row::fromValues([XlsxFile::safe($label), XlsxFile::safe($value)]));
        }
        $writer->close();

        return $count;
    }

    /** @param  array<string, mixed>  $row */
    private static function cell(ExportColumn $column, array $row, DateTimeZone $timezone): mixed
    {
        $value = $column->raw($row);
        if ($value === null || $value === '') {
            return null;
        }

        return match ($column->type) {
            ExportColumn::INTEGER => (int) $value,
            ExportColumn::DECIMAL => (float) $value,
            ExportColumn::BOOLEAN => $value === true || $value === 't' || $value === 'true' ? 'Yes' : 'No',
            ExportColumn::DATE => DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10)) ?: XlsxFile::safe((string) $value),
            // spreadsheets have no time zones: the tenant's local time, named on the About sheet
            ExportColumn::INSTANT => (new DateTimeImmutable((string) $value))->setTimezone($timezone),
            default => XlsxFile::safe(is_scalar($value) ? (string) $value : ''),
        };
    }

    private static function width(ExportColumn $column): float
    {
        $content = match ($column->type) {
            ExportColumn::DATE => 12,
            ExportColumn::INSTANT => 17,
            ExportColumn::INTEGER, ExportColumn::DECIMAL, ExportColumn::BOOLEAN => 10,
            default => 24,
        };

        return (float) max($content, min(40, mb_strlen($column->header) + 4));
    }

    /** Excel sheet names: at most 31 characters, none of : \ / ? * [ ]. */
    private static function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $title));

        return mb_substr($name === '' ? 'Export' : $name, 0, 31);
    }
}
