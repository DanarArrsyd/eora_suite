<?php

namespace App\Providers;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\ContextSigner;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
    }
}
