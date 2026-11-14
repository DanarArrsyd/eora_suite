<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Demo\DemoStock;
use App\Platform\Console\ReadsTextInput;
use App\Platform\Demo\DemoSpec;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local UAT data (doc 32 §1, M3 exit): `php artisan eora:demo:stock` after `eora:demo:master` posts the opening stock
 * through the real receipt and incoming-release endpoints (DemoStock). A load cut off half-way finishes when run again;
 * refused in production, once loaded, and for a tenant with stock postings of its own.
 */
final class LoadDemoStock extends Command
{
    use ReadsTextInput;

    protected $signature = 'eora:demo:stock {--code=demo-stamping : Tenant code of a seeded demo tenant with its sample master data}';

    protected $description = 'Post the opening stock of a demo tenant through the real postings (local UAT only)';

    public function handle(DemoStock $stock): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo stock never loads in production.');

            return self::FAILURE;
        }
        try {
            $spec = new DemoSpec($this->textOption('code'));
            $posted = $stock->load($spec, fn (string $line) => $this->line($line));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (DomainProblem $e) {
            // a posting refused half-way (it is a RuntimeException too): what was posted stays
            $this->error("A posting was refused: {$e->title}. {$e->detail} Fix it and run the command again to finish the load.");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            // a posting refused half-way: what was posted stays, and running the command again finishes the load
            $this->error('A posting was refused: '.implode(' ', array_merge(...array_values($e->errors()))).' Fix it and run the command again to finish the load.');

            return self::FAILURE;
        }
        $this->info(sprintf('Demo stock loaded into %s: %d documents posted. Coil HEAT-A101 and the sheets wait for their incoming release (qci@).', $spec->code, count($posted)));

        return self::SUCCESS;
    }
}
