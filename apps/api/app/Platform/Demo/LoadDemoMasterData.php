<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local UAT data (doc 29 §2, M2 exit): `php artisan eora:demo:master` after `eora:demo:seed` loads the sample master
 * data through the import pipeline (DemoMasterData). Needs the import worker running; refused in production and when
 * the sample set is loaded already.
 */
final class LoadDemoMasterData extends Command
{
    protected $signature = 'eora:demo:master
        {--code=demo-stamping : Tenant code of a seeded demo tenant}
        {--from= : First effective date YYYY-MM-DD of routings, BOMs and customer items (default today at plant P1)}
        {--wait=300 : Seconds to wait for the import worker per step}';

    protected $description = 'Load the sample master data into a demo tenant through the import pipeline (local UAT only)';

    public function handle(DemoMasterData $master): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo master data never loads in production.');

            return self::FAILURE;
        }
        $from = (string) $this->option('from');
        if ($from !== '' && ! ImportSamples::isDate($from)) {
            $this->error('--from must be a date as YYYY-MM-DD.');

            return self::INVALID;
        }

        try {
            $spec = new DemoSpec((string) $this->option('code'));
            $loaded = $master->load($spec, $from !== '' ? $from : null, fn (string $template, int $rows) => $this->line(sprintf('%-22s %4d rows committed', $template, $rows)), max(1, (int) $this->option('wait')));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Demo master data loaded into %s: %d files, %d rows. Routings and BOMs are Draft: submit (ppc@) and approve (csa@) them in UAT.',
            $spec->code, count($loaded), array_sum(array_column($loaded, 'rows'))));

        return self::SUCCESS;
    }
}
