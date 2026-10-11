<?php

use App\Platform\Health\HealthChecker;

it('answers liveness without touching dependencies and returns a correlation id', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertHeader('X-Correlation-Id');
});

it('echoes a well-formed X-Request-Id and replaces a malformed one', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'req-12345678'])->assertHeader('X-Correlation-Id', 'req-12345678');
    $id = $this->getJson('/api/v1/health', ['X-Request-Id' => 'bad id!'])->headers->get('X-Correlation-Id');
    expect($id)->not->toBe('bad id!')->and(strlen((string) $id))->toBe(26);
});

it('reports degraded readiness with 503 when a dependency fails', function () {
    $this->app->instance(HealthChecker::class, new class extends HealthChecker
    {
        public function database(): array
        {
            return ['status' => 'fail', 'latencyMs' => 3.0, 'error' => 'QueryException'];
        }

        public function redis(): array
        {
            return ['status' => 'ok', 'latencyMs' => 0.4, 'version' => '8.6.0'];
        }
    });

    $this->getJson('/api/v1/health/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'fail')
        ->assertJsonPath('checks.redis.status', 'ok');
});

it('hides dependency versions and errors in production', function () {
    $this->app['env'] = 'production';
    $this->app->instance(HealthChecker::class, new class extends HealthChecker
    {
        public function database(): array
        {
            return ['status' => 'ok', 'latencyMs' => 1.0, 'version' => '18.0'];
        }

        public function redis(): array
        {
            return ['status' => 'ok', 'latencyMs' => 0.4, 'version' => '8.6.0'];
        }
    });

    $this->getJson('/api/v1/health/ready')->assertOk()->assertJsonMissingPath('checks.database.version');
});

it('reaches the real PostgreSQL and Redis services', function () {
    $this->getJson('/api/v1/health/ready')
        ->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok');
})->group('integration');

it('runs migrations against PostgreSQL as the migrations owner', function () {
    // DDL belongs to eora_owner (doc 25 §5.1); the runtime role eora_app cannot create or drop tables.
    $this->artisan('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true])->assertSuccessful();
    expect(DB::connection('pgsql_owner')->getDriverName())->toBe('pgsql')
        ->and(Schema::connection('pgsql_owner')->hasTable('migrations'))->toBeTrue();
})->group('integration');
