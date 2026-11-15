<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Console;

use App\Modules\Delivery\Demo\DemoOrder;
use App\Platform\Console\ReadsTextInput;
use App\Platform\Demo\DemoSpec;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local UAT data (doc 33 §1.9, D-118, M4 exit): `php artisan eora:demo:m4` after `eora:demo:master` and `eora:demo:stock`
 * runs one full order through the real endpoints (DemoOrder). Safe to repeat; refused in production.
 */
final class LoadDemoOrder extends Command
{
    use ReadsTextInput;

    protected $signature = 'eora:demo:m4 {--code=demo-stamping : Tenant code of a seeded demo tenant with its sample master data and opening stock}';

    protected $description = 'Run one full demo order (SO → WO → issue → production → QC → FG → pick → ship) through the real endpoints (local UAT only)';

    public function handle(DemoOrder $order): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo order never runs in production.');

            return self::FAILURE;
        }
        try {
            $spec = new DemoSpec($this->textOption('code'));
            $done = $order->run($spec, fn (string $line) => $this->line($line));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (DomainProblem $e) {
            // a step refused half-way (it is a RuntimeException too): what was done stays, running again finishes
            $this->error("A step was refused: {$e->title}. {$e->detail} Fix it and run the command again to finish the order.");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error('A step was refused: '.implode(' ', array_merge(...array_values($e->errors()))).' Fix it and run the command again to finish the order.');

            return self::FAILURE;
        }
        $this->info(sprintf('Demo order done in %s: %s → %s → lot %s → %s. Trace it in Traceability → Backward Trace (csa@, inv@ or aud@).',
            $spec->code, $done['so'], $done['wo'], $done['lot'], $done['shipment']));

        return self::SUCCESS;
    }
}
