<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

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
    });
});

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
