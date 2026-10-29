<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Import\ImportTemplates;
use Illuminate\Console\Command;

/**
 * Local UAT (doc 29 §1.6, D-081): writes the sample import files (ImportSamples) as filled templates of the current
 * template versions, numbered in import order, plus `99-items-with-errors.xlsx`. Reads no tenant data. A sample row
 * naming a header the template does not have stops the command, so the samples cannot drift from the templates.
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
        if (! ImportSamples::isDate($from)) {
            $this->error('--from must be a date as YYYY-MM-DD.');

            return self::INVALID;
        }
        $dir = rtrim((string) $this->argument('dir'), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("The folder {$dir} could not be created.");

            return self::FAILURE;
        }

        try {
            foreach (ImportSamples::write($templates, $dir, $from) as $file) {
                $this->line(sprintf('%s  %d rows', basename($file['path']), $file['rows']));
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
