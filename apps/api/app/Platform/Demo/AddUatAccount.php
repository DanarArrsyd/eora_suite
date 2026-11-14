<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Auth\PasswordPolicy;
use App\Platform\Console\ReadsTextInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local UAT (owner, 2026-11-13): `php artisan eora:demo:uat-account --password=<demo password>` adds `uat@<code>.test`
 * holding every role of an existing demo tenant. Refused in production. Its approvals still need a second account
 * (D-060): it never approves what it requested itself.
 */
final class AddUatAccount extends Command
{
    use ReadsTextInput;

    protected $signature = 'eora:demo:uat-account
        {--code=demo-stamping : Demo tenant code}
        {--password= : Password of the new account (use the shared demo password); kept when the account exists already}';

    protected $description = 'Add the all-roles UAT account uat@<code>.test to a demo tenant (local UAT only)';

    public function handle(DemoSeeder $seeder): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('The demo UAT account is never created in production.');

            return self::FAILURE;
        }
        $password = $this->textOption('password');
        $check = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:12', 'max:1024', new PasswordPolicy]]);
        if ($check->fails()) {
            $this->error('--password: '.$check->errors()->first('password'));

            return self::INVALID;
        }

        try {
            $result = $seeder->addUatAccount(new DemoSpec($this->textOption('code'), 'Demo'), $password);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($result['created'] ? 'Created ' : 'Updated ').$result['email'].' with every role: '.implode(', ', $result['roles']).'.');
        $this->line($result['created'] ? 'Password: the one you passed with --password.' : 'The account existed already; its password is unchanged.');
        $this->line('Approvals still need a second account (e.g. csa@): no one approves what they requested themselves (D-060).');

        return self::SUCCESS;
    }
}
