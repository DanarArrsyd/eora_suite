<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProbeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['qty' => 'required|decimal:0,3|gt:0'];
    }
}

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_probe')->group(function () {
        Route::post('validate', fn (ProbeRequest $r) => response()->json(['ok' => true]));
        Route::get('boom', fn () => throw new RuntimeException('secret internal detail'));
        Route::get('gate-deny', fn () => Gate::authorize('probe-deny'));
        Route::get('gate-hide', fn () => Gate::authorize('probe-hide'));
        Route::get('authz', fn () => throw new AuthorizationException('policy: needs plant.manage on plant 7'));
        Route::get('access-denied', fn () => throw new AccessDeniedHttpException('policy: needs plant.manage on plant 7'));
        Route::get('abort/{status}', fn (int $status) => abort($status, 'internal abort reason'));
        Route::get('unauthenticated', fn () => throw new AuthenticationException('session lookup failed for user 42'));
        Route::get('throttled', fn () => throw new ThrottleRequestsException);
    });
    // $user = null lets the ability run for guests; otherwise Gate denies before the callback runs.
    Gate::define('probe-deny', fn ($user = null) => Response::deny('policy: needs plant.manage on plant 7'));
    Gate::define('probe-hide', fn ($user = null) => Response::denyAsNotFound('policy: record belongs to tenant 9'));
});

/** Asserts the RFC 9457 envelope and that no internal reason leaks into the body. */
function expectProblem(TestResponse $res, int $status, string $code): void
{
    $res->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json');
    expect($res->json())->toMatchArray(['status' => $status, 'code' => $code, 'type' => 'https://errors.eora.app/'.$code])
        ->and($res->json('correlationId'))->toBe($res->headers->get('X-Correlation-Id'))
        ->and($res->json('reference'))->toMatch('/^[a-z0-9]{4}-[a-z0-9]{4}$/')
        ->and($res->json('detail'))->toBeNull()
        ->and($res->getContent())->not->toContain('policy:')->not->toContain('internal abort reason')->not->toContain('user 42');
}

it('maps authorization failures to 403 FORBIDDEN without exposing the policy reason', function (string $path) {
    expectProblem($this->getJson("/api/v1/_probe/{$path}"), 403, 'FORBIDDEN');
})->with([
    'denied Gate::authorize()' => 'gate-deny',
    'AuthorizationException' => 'authz',
    'AccessDeniedHttpException' => 'access-denied',
    'abort(403)' => 'abort/403',
]);

it('maps a policy denyAsNotFound() to 404 NOT_FOUND (no existence disclosure)', function () {
    expectProblem($this->getJson('/api/v1/_probe/gate-hide'), 404, 'NOT_FOUND');
});

it('keeps 401 UNAUTHENTICATED for authentication failures', function (string $path) {
    expectProblem($this->getJson("/api/v1/_probe/{$path}"), 401, 'UNAUTHENTICATED');
})->with(['AuthenticationException' => 'unauthenticated', 'abort(401)' => 'abort/401']);

it('keeps the remaining client error codes', function (string $path, int $status, string $code) {
    expectProblem($this->getJson("/api/v1/_probe/{$path}"), $status, $code);
})->with([
    'abort(400)' => ['abort/400', 400, 'MALFORMED_REQUEST'],
    'abort(404)' => ['abort/404', 404, 'NOT_FOUND'],
    'abort(409)' => ['abort/409', 409, 'MALFORMED_REQUEST'],
    'throttled' => ['throttled', 429, 'RATE_LIMITED'],
]);

it('renders unknown API routes as problem+json 404', function () {
    $res = $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
    expect($res->json())->toMatchArray(['code' => 'NOT_FOUND', 'status' => 404, 'type' => 'https://errors.eora.app/NOT_FOUND'])
        ->and($res->json('correlationId'))->toBe($res->headers->get('X-Correlation-Id'))
        ->and($res->json('reference'))->toMatch('/^[a-z0-9]{4}-[a-z0-9]{4}$/');
});

it('maps FormRequest validation to 422 VALIDATION_FAILED with field errors', function () {
    $this->postJson('/api/v1/_probe/validate', ['qty' => 'abc'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['qty']]);
    $this->postJson('/api/v1/_probe/validate', ['qty' => '12.345'])->assertOk();
});

it('never leaks internal messages when debug is off', function () {
    config(['app.debug' => false]);
    $res = $this->getJson('/api/v1/_probe/boom')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
    expect($res->getContent())->not->toContain('secret internal detail');
});

it('answers 405 for a wrong method', function () {
    $this->postJson('/api/v1/health')->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
});
