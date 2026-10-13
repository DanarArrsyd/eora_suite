<?php

namespace App\Providers;

use App\Platform\Auth\AuthSession;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Http\OpenApi\ProblemResponses;
use App\Platform\Security\SecurityEventRecorder;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\ContextSigner;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\IpUtils;

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
        // ADR-0006 topology: resolve the client IP only through explicitly trusted proxy hops (login limits, D-B1-06 NAT
        // allowlist, security-event IP hashes); nothing is trusted unless configured.
        $proxies = (array) config('eora.trusted_proxies');
        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }

        // OpenAPI error contract: problem+json responses with the catalogued codes per endpoint (doc 24 §5)
        Scramble::configure()
            ->withOperationTransformers(ProblemResponses::class)
            ->withDocumentTransformers(ProblemResponses::class);

        // migrate:fresh, db:wipe and friends are refused in production (doc 25 §10).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // D-B1-06: 5/min per account and 20/min per IP. Limiter keys hold only pepper-keyed HMACs (D-B1-22): the cache
        // never stores an email address or a client IP, and a low-entropy email cannot be dictionary-tested from it.
        RateLimiter::for('login', function (Request $request): array {
            $hasher = app(SecurityHasher::class);
            $email = mb_strtolower(trim((string) $request->input('email')));

            // D-B1-06: allow-listed factory NAT addresses (many workers behind one IP) get 60/min per IP
            $nat = IpUtils::checkIp((string) $request->ip(), (array) config('eora.login_nat_allowlist'));

            return [
                Limit::perMinute(5)->by('login:account:'.($hasher->hash($email) ?? 'none')),
                Limit::perMinute($nat ? 60 : 20)->by('login:ip:'.($hasher->hash((string) $request->ip()) ?? 'none')),
            ];
        });
        // Per-administrator cap on member invitations and resends: configurable, off unless EORA_MEMBER_INVITE_PER_HOUR is
        // set (no approved value yet; doc 25 §2.4 places per-inviter limits at the HTTP layer).
        RateLimiter::for('member-invite', function () {
            $perHour = config('eora.member_invite_per_hour');

            return is_int($perHour) && $perHour > 0
                ? Limit::perHour($perHour)->by('member-invite:'.(app(AuthSession::class)->userId() ?? 'none'))
                : Limit::none();
        });
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(10)->by(
            'invite:ip:'.(app(SecurityHasher::class)->hash((string) $request->ip()) ?? 'none'),
        ));
    }
}
