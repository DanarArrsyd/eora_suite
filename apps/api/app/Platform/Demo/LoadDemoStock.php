<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local UAT data (doc 32 §1, M3 exit): `php artisan eora:demo:stock` after `eora:demo:master` posts the opening stock
 * through the real receipt and incoming-release endpoints (DemoStock). Refused in production and when the tenant has
 * stock postings already.
 */
final class LoadDemoStock extends Command
{
    protected $signature = 'eora:demo:stock {--code=demo-stamping : Tenant code of a seeded demo tenant with its sample master data}';

    protected $description = 'Post the opening stock of a demo tenant through the real postings (local UAT only)';

    public function handle(DemoStock $stock): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo stock never loads in production.');

            return self::FAILURE;
        }
        try {
            $spec = new DemoSpec((string) $this->option('code'));
            $posted = $stock->load($spec, fn (string $line) => $this->line($line));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Demo stock loaded into %s: %d documents posted. Coil HEAT-A101 and the sheets wait for their incoming release (qci@).', $spec->code, count($posted)));

        return self::SUCCESS;
    }
}
