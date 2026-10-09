<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

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
