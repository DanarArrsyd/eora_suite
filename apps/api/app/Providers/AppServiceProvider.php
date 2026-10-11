<?php

namespace App\Providers;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityEventRecorder;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\ContextSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every pgsql connection merges BEGIN with the transaction binding read (signed context, D-055).
        Connection::resolverFor('pgsql', static fn ($pdo, string $database, string $prefix, array $config) => new EoraPostgresConnection($pdo, $database, $prefix, $config));

        // request/job scoped: flushed after the outer transaction, reset between jobs and Octane requests
        $this->app->scoped(SecurityEventRecorder::class);
        $this->app->singleton(SecurityHasher::class, static fn ($app): SecurityHasher => new SecurityHasher((string) $app['config']->get('eora.pepper.key')));

        $this->app->singleton(ContextSigner::class, static fn ($app): ContextSigner => new ContextSigner(
            (string) $app['config']->get('eora.context.kid'),
            (string) $app['config']->get('eora.context.key'),
            (int) $app['config']->get('eora.context.ttl'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // migrate:fresh, db:wipe and friends are refused in production (doc 25 §10).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // D-B1-06: 5/min per account (keyed by the email's HMAC, never the email) and 20/min per IP
        RateLimiter::for('login', function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:account:'.hash('sha256', $email)),
                Limit::perMinute(20)->by('login:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(10)->by('invite:ip:'.$request->ip()));
    }
}
