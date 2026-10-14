<?php

declare(strict_types=1);

use App\Platform\Http\OpenApi\ProblemResponses;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

/** @return array<string, mixed> the generated OpenAPI document, same source as `composer openapi:export` */
function openApiDocument(): array
{
    return app(Generator::class)(Scramble::getGeneratorConfig('default'));
}

it('documents readiness as 200 ok and 503 degraded with the real check shape', function () {
    $responses = openApiDocument()['paths']['/health/ready']['get']['responses'];

    expect(array_keys($responses))->toEqualCanonicalizing([200, 503]);

    foreach ([200 => 'ok', 503 => 'degraded'] as $status => $overall) {
        $schema = $responses[$status]['content']['application/json']['schema'];
        $check = $schema['properties']['checks']['properties']['database'];

        expect($schema['properties']['status'])->toBe(['type' => 'string', 'const' => $overall])
            ->and($schema['required'])->toEqualCanonicalizing(['status', 'checks', 'time'])
            ->and($check['properties']['status']['enum'])->toEqualCanonicalizing(['ok', 'fail'])
            ->and(array_keys($check['properties']))->toEqualCanonicalizing(['status', 'latencyMs', 'version', 'error'])
            ->and($check['required'])->toEqualCanonicalizing(['status', 'latencyMs'])
            ->and($schema['properties']['checks']['properties']['redis'])->toBe($check);
    }
});

it('leaves the liveness contract unchanged (200 only)', function () {
    expect(array_keys(openApiDocument()['paths']['/health']['get']['responses']))->toBe([200]);
});

it('documents every v1 error as problem+json with codes from the doc 24 §5 catalogue', function () {
    $catalogue = ['MALFORMED_REQUEST', 'UNAUTHENTICATED', 'SESSION_EXPIRED', 'FORBIDDEN', 'SCOPE_FORBIDDEN', 'SOD_VIOLATION', 'STEP_UP_REQUIRED',
        'NOT_FOUND', 'METHOD_NOT_ALLOWED', 'INVALID_STATE_TRANSITION', 'VERSION_CONFLICT', 'CSRF_TOKEN_MISMATCH', 'VALIDATION_FAILED',
        'RATE_LIMITED', 'RETRYABLE_CONFLICT', 'INTERNAL_ERROR', 'FILTER_NOT_ALLOWED', 'PRECONDITION_REQUIRED'];
    $routes = collect(Route::getRoutes()->getRoutes())->map->getName()->filter(fn ($n) => is_string($n) && str_starts_with($n, 'v1.'))->values();
    $declared = array_keys(ProblemResponses::ERRORS);

    expect($routes->diff($declared)->all())->toBe([]); // a new route must declare its errors
    $document = openApiDocument();
    expect($document['components']['responses'] ?? [])->toBe([]);

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status < 400 || $path === '/health/ready') {
                    continue;
                }
                $schema = $response['content']['application/problem+json']['schema'] ?? null;
                expect($schema)->not->toBeNull("{$method} {$path} {$status} is not problem+json")
                    ->and($schema['properties']['status']['enum'])->toBe([(int) $status])
                    ->and(array_diff($schema['properties']['code']['enum'], $catalogue))->toBe([]);
            }
        }
    }
});
