<?php

declare(strict_types=1);

namespace App\Platform\Import;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Checks an uploaded file before anything is stored (ADR-0017 §3, M2-D3): an XLSX workbook (a zip with the workbook
 * parts), at most 10 MB, no zip bomb, no macros, no links to other workbooks. Antivirus scanning joins here in M6.
 */
final class UploadInspector
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const int MAX_UNCOMPRESSED = 100 * 1024 * 1024;

    private const int MAX_ENTRIES = 2000;

    public static function check(string $path, int $size): void
    {
        $fail = static fn (string $message) => throw ValidationException::withMessages(['file' => $message]);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            $fail('Upload an Excel file of at most 10 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $fail('This is not an Excel workbook (.xlsx). Download the template and fill it in.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $fail('This is not an Excel workbook (.xlsx). Download the template and fill it in.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    $fail('The workbook could not be read.');
                }
                $name = strtolower((string) $stat['name']);
                if (str_ends_with($name, 'vbaproject.bin')) {
                    $fail('Workbooks with macros are not accepted. Save it as .xlsx without macros.');
                }
                if (str_starts_with($name, 'xl/externallinks/')) {
                    $fail('The workbook links to other files. Remove the external links (paste values) and upload again.');
                }
                $total += (int) $stat['size'];
                // zip bomb guard on the declared uncompressed sizes
                if ($total > self::MAX_UNCOMPRESSED) {
                    $fail('The workbook expands to an unusual size and was not accepted.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
