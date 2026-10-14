<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Auth\PasswordPolicy;
use App\Platform\Provisioning\ProvisioningException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local UAT data (doc 28 §1): `php artisan eora:demo:seed` in the one-shot runner. Refused in production. Prints the
 * demo accounts and the shared demo password once; the password is not stored anywhere else.
 */
final class SeedDemoTenant extends Command
{
    protected $signature = 'eora:demo:seed
        {--code=demo-stamping : Tenant code; accounts are <role>@<code>.test}
        {--name=Demo Stamping : Tenant display name}
        {--password= : Shared demo password (12+ characters, not breached); generated when omitted}';

    protected $description = 'Create the synthetic "Demo Stamping" tenant with one active user per role template (local UAT only)';

    public function handle(DemoSeeder $seeder): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo seeder never runs in production.');

            return self::FAILURE;
        }

        $given = (string) $this->option('password') !== '';
        $password = $given ? (string) $this->option('password') : 'Demo-'.Str::password(16, symbols: false);
        $check = Validator::make(['password' => $password], ['password' => ['string', 'min:12', 'max:1024', new PasswordPolicy]]);
        if ($check->fails()) {
            $this->error((string) $check->errors()->first('password'));

            return self::INVALID;
        }

        try {
            $spec = new DemoSpec((string) $this->option('code'), (string) $this->option('name'));
            ['resumed' => $resumed, 'accounts' => $accounts] = $seeder->seed($spec, $password, $given);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (DemoAlreadySeeded $e) {
            $this->error($e->getMessage().' Seeding never changes an existing tenant.');
            $this->line('Reset the local demo with a fresh local database: docker compose down -v, docker compose up -d --wait, then run this command again.');
            $this->line('Or seed a second demo tenant next to it: --code=demo-stamping-2');

            return self::FAILURE;
        } catch (DemoResumeNeedsPassword $e) {
            $this->error($e->getMessage().' Run again with --password=<the password printed by the interrupted run>.');

            return self::FAILURE;
        } catch (ProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo tenant \"{$spec->name}\" ({$spec->code}) ".($resumed ? 'completed' : 'created').' with '.count($accounts).' active users. All data is synthetic.');
        $this->table(['Role', 'Name', 'E-mail', 'Scope'], array_map(static fn (array $a) => [$a['role'], $a['name'], $a['email'], $a['scope']], $accounts));
        $this->line($resumed && $given ? 'Password for every demo account: the one you passed with --password.' : 'Password for every demo account (shown once, not stored): '.$password);
        $this->line('Sign in at '.rtrim((string) config('eora.frontend_url'), '/').'/login');

        return self::SUCCESS;
    }
}
