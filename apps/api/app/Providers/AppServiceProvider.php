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

        // D-B1-06: 5/min per account and 20/min per IP. Limiter keys hold only pepper-keyed HMACs (D-B1-22): the cache
        // never stores an email address or a client IP, and a low-entropy email cannot be dictionary-tested from it.
        RateLimiter::for('login', function (Request $request): array {
            $hasher = app(SecurityHasher::class);
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:account:'.($hasher->hash($email) ?? 'none')),
                Limit::perMinute(20)->by('login:ip:'.($hasher->hash((string) $request->ip()) ?? 'none')),
            ];
        });
        // forgot-password flooding stops at HTTP before the database (doc 25 §2.3): per IP and per email HMAC
        RateLimiter::for('password-forgot', function (Request $request): array {
            $hasher = app(SecurityHasher::class);

            return [
                Limit::perMinute(3)->by('forgot:account:'.($hasher->hash(mb_strtolower(trim((string) $request->input('email')))) ?? 'none')),
                Limit::perMinute(10)->by('forgot:ip:'.($hasher->hash((string) $request->ip()) ?? 'none')),
            ];
        });
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(10)->by(
            'invite:ip:'.(app(SecurityHasher::class)->hash((string) $request->ip()) ?? 'none'),
        ));
    }
}
