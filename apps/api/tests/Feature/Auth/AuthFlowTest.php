<?php

declare(strict_types=1);

use App\Platform\Auth\Delivery\InvitationMail;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/*
 * Group B (doc 25 §11, §15.3): the HTTP authentication flow through the real roles — invitation email from the
 * delivery worker, acceptance, login, tenant/plant selection, CSRF, lockout, session expiry, TenantRequest.
 */

const ORIGIN = 'http://localhost';

/** Stateful SPA request: own origin + the session's CSRF token. */
function spa(TestCase $test): TestCase
{
    $test->get('/sanctum/csrf-cookie', ['Origin' => ORIGIN, 'Referer' => ORIGIN.'/']);

    return $test->withHeaders(['Origin' => ORIGIN, 'Referer' => ORIGIN.'/', 'X-CSRF-TOKEN' => (string) session()->token(), 'Accept' => 'application/json']);
}

/** Invites $email into $tenant and returns the one-time token from the email the worker sent. */
function invitationToken(TestCase $test, array $tenant, string $email): string
{
    Mail::fake();
    IdentityFixtures::invite($tenant, $email);
    $test->artisan('eora:deliver-invitations', ['--once' => true])->assertSuccessful();
    $token = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($email, &$token) {
        if ($mail->hasTo($email) && preg_match('/#token=([0-9a-f]{64})$/', $mail->link, $m) === 1) {
            $token = $m[1];

            return true;
        }

        return false;
    });

    return (string) $token;
}

/** @return array{email: string, password: string} */
function activeUser(TestCase $test, array $tenant): array
{
    $email = IdentityFixtures::email();
    $password = 'correct horse battery staple';
    spa($test)->postJson('/api/v1/auth/invitations/accept', ['token' => invitationToken($test, $tenant, $email), 'password' => $password, 'password_confirmation' => $password])
        ->assertNoContent();

    return ['email' => $email, 'password' => $password];
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
});

it('runs the whole flow: invitation email → accept → login → tenant → plant → logout', function () {
    $u = activeUser($this, $this->t);

    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk()
        ->assertJsonPath('user.email', $u['email'])
        ->assertJsonPath('tenants.0.id', $this->t['tenant'])
        ->assertJsonPath('activeTenantId', null);

    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $this->t['tenant']])->assertOk()
        ->assertJsonPath('activeTenantId', $this->t['tenant'])
        ->assertJsonCount(2, 'plants');

    spa($this)->postJson('/api/v1/auth/plant', ['plantId' => $this->t['plants'][1]])->assertOk()
        ->assertJsonPath('activePlantId', $this->t['plants'][1]);

    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();
    spa($this)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
})->group('integration', 'auth');

it('answers every failed login with the same 401 body', function () {
    $u = activeUser($this, $this->t);

    $wrong = spa($this)->postJson('/api/v1/auth/login', ['email' => $u['email'], 'password' => 'wrong password!'])->assertUnauthorized()->json();
    $unknown = spa($this)->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever12345'])->assertUnauthorized()->json();

    unset($wrong['correlationId'], $wrong['reference'], $unknown['correlationId'], $unknown['reference']);
    expect($wrong)->toBe($unknown)->and($wrong['code'])->toBe('UNAUTHENTICATED');
})->group('integration', 'auth');

it('rejects a stateful request without the CSRF token (419 CSRF_TOKEN_MISMATCH)', function () {
    $this->withHeaders(['Origin' => ORIGIN, 'Referer' => ORIGIN.'/', 'Accept' => 'application/json'])
        ->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'whatever12345'])
        ->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
})->group('integration', 'auth');

it('limits login attempts per account (D-B1-06: 5 per minute)', function () {
    $email = IdentityFixtures::email();
    for ($i = 0; $i < 5; $i++) {
        spa($this)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'whatever12345'])->assertUnauthorized();
    }
    spa($this)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'whatever12345'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
})->group('integration', 'auth');

it('refuses a used, wrong or malformed invitation token with the same validation error', function () {
    $email = IdentityFixtures::email();
    $token = invitationToken($this, $this->t, $email);
    $body = ['password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple'];

    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $token] + $body)->assertNoContent();
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $token] + $body)->assertStatus(422)->assertJsonPath('errors.token.0', 'This invitation link is invalid or has expired.');
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => str_repeat('a', 64)] + $body)->assertStatus(422);
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => 'short', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'password']);
})->group('integration', 'auth');

it('does not let a user select a tenant without an ACTIVE membership', function () {
    $u = activeUser($this, $this->t);
    $other = TenantFixtures::provision(1);

    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $other['tenant']])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
})->group('integration', 'auth');

it('expires the session after the absolute lifetime and when the membership is revoked', function () {
    $u = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $this->t['tenant']])->assertOk();

    app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), fn (EoraPostgresConnection $db) => $db->update(
        "update memberships set status = 'SUSPENDED' where user_id = (select id from users where email_display = ?)", [$u['email']],
    ));
    spa($this)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');

    $v = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $v)->assertOk();
    $this->travel(13)->hours();
    spa($this)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
})->group('integration', 'auth');

it('runs business routes in one tenant transaction: errors roll back, GET is read-only', function () {
    Route::middleware(['api', 'tenant'])->post('/api/v1/_test/companies/{code}/{status}', function (string $code, int $status) {
        app('db')->connection()->insert('insert into companies (tenant_id, code, legal_name) values ((select c.tenant from ctx_api.context() c), ?, ?)', [$code, 'Probe']);

        return response()->json([], $status);
    });
    Route::middleware(['api', 'tenant'])->get('/api/v1/_test/write', function () {
        app('db')->connection()->insert("insert into companies (tenant_id, code, legal_name) values ((select c.tenant from ctx_api.context() c), 'RO', 'x')");

        return response()->json([]);
    });
    $u = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $this->t['tenant']])->assertOk();

    spa($this)->postJson('/api/v1/_test/companies/KEEP/201')->assertCreated();
    spa($this)->postJson('/api/v1/_test/companies/DROP/422')->assertStatus(422);
    spa($this)->getJson('/api/v1/_test/write')->assertStatus(500);

    $codes = app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), fn (EoraPostgresConnection $db) => array_column($db->select("select code from companies where code in ('KEEP', 'DROP', 'RO')"), 'code'));
    expect($codes)->toBe(['KEEP']);
})->group('integration', 'auth');

it('records a denied access of a signed-in user as a durable security event of the tenant', function () {
    Route::middleware(['api', 'tenant'])->get('/api/v1/_test/forbidden', fn () => abort(403))->name('test.forbidden');
    $u = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $this->t['tenant']])->assertOk();

    spa($this)->getJson('/api/v1/_test/forbidden')->assertForbidden();

    $events = app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), fn (EoraPostgresConnection $db) => $db->select(
        "select action, route, http_status from security_events where source = 'app' and route = 'test.forbidden'",
    ));
    expect($events)->toHaveCount(1)->and($events[0]->action)->toBe('access.forbidden')->and($events[0]->http_status)->toBe(403);
})->group('integration', 'auth');

it('expires business-route sessions after the absolute lifetime too', function () {
    Route::middleware(['api', 'tenant'])->get('/api/v1/_test/ping', fn () => response()->json(['ok' => true]));
    $u = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    spa($this)->postJson('/api/v1/auth/tenant', ['tenantId' => $this->t['tenant']])->assertOk();
    spa($this)->getJson('/api/v1/_test/ping')->assertOk();

    $this->travel(13)->hours();
    spa($this)->getJson('/api/v1/_test/ping')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
})->group('integration', 'auth');
