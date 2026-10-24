<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Import\ImportColumn;
use App\Platform\Import\ImportTemplates;
use App\Platform\Import\XlsxFile;
use Illuminate\Console\Command;

/**
 * Local UAT (doc 29 §1.6, D-081): writes the sample import files (ImportSamples) as filled templates of the current
 * template versions, numbered in import order. Reads no tenant data. A sample row naming a header the template does not
 * have stops the command, so the samples cannot drift from the templates unnoticed.
 */
final class WriteImportSamples extends Command
{
    protected $signature = 'eora:demo:import-samples
        {dir : Folder to write the .xlsx files to (created when missing)}
        {--from= : First effective date YYYY-MM-DD (today or later when imported; default today)}';

    protected $description = 'Write the sample import files for UAT (local only)';

    public function handle(ImportTemplates $templates): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Sample files are for local UAT only.');

            return self::FAILURE;
        }
        $from = (string) ($this->option('from') ?: now('Asia/Jakarta')->toDateString());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || $date === false || $date->format('Y-m-d') !== $from) {
            $this->error('--from must be a date as YYYY-MM-DD.');

            return self::INVALID;
        }
        $dir = rtrim((string) $this->argument('dir'), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("The folder {$dir} could not be created.");

            return self::FAILURE;
        }

        foreach (ImportSamples::files($from) as $i => [$code, $rows]) {
            $template = $templates->get($code);
            $headers = array_map(static fn (ImportColumn $c) => $c->header, $template->columns());
            foreach ($rows as $row) {
                $unknown = array_diff(array_keys($row), $headers);
                if ($unknown !== []) {
                    $this->error(sprintf('Sample %s names headers the template does not have: %s.', $code, implode(', ', $unknown)));

                    return self::FAILURE;
                }
            }
            $path = sprintf('%s/%02d-%s.xlsx', $dir, $i + 1, str_replace('_', '-', $code));
            XlsxFile::write($path, $headers, array_map(static fn (array $row) => array_map(static fn (string $h) => $row[$h] ?? '', $headers), $rows));
            $this->line(sprintf('%s  %d rows', basename($path), count($rows)));
        }

        return self::SUCCESS;
    }
}
