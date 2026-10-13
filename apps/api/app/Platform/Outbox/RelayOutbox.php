<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Console\Command;

/** Long-running outbox relay process (doc 25 §6.4); takes no user input. */
final class RelayOutbox extends Command
{
    protected $signature = 'eora:outbox:relay {--once : Process what is due, then exit} {--sleep=2 : Seconds between polls when idle}';

    protected $description = 'Relay outbox events to their consumers (claims as eora_relay, handles as eora_app per tenant)';

    public function handle(OutboxRelay $relay): int
    {
        $worker = 'relay:'.substr(gethostname() ?: 'host', 0, 40).':'.getmypid();

        do {
            $claimed = $relay->runOnce($worker);
            if (! $this->option('once') && $claimed === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
