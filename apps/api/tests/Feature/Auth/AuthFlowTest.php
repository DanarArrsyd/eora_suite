<?php

declare(strict_types=1);

use App\Platform\Auth\Delivery\PasswordResetMail;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;

/*
 * Group B (doc 25 §11, §15.3): the HTTP authentication flow through the real roles — invitation email from the
 * delivery worker, acceptance, login, tenant/plant selection, CSRF, lockout, session expiry, TenantRequest.
 */

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

it('T-RST: forgot → reset email → new password; old password and old sessions stop working', function () {
    $u = activeUser($this, $this->t);
    $token = resetToken($this, $u['email']);
    expect($token)->toMatch('/^[0-9a-f]{64}$/');

    $new = 'a brand new passphrase 2026';
    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => $new, 'password_confirmation' => $new])->assertNoContent();
    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => $new, 'password_confirmation' => $new])
        ->assertStatus(422)->assertJsonPath('errors.token.0', 'This reset link is invalid or has expired.'); // single use

    spa($this)->postJson('/api/v1/auth/login', $u)->assertUnauthorized();
    spa($this)->postJson('/api/v1/auth/login', ['email' => $u['email'], 'password' => $new])->assertOk();
})->group('integration', 'auth');

it('T-RST: a completed reset ends every existing session of the user (credential version)', function () {
    $u = activeUser($this, $this->t);
    spa($this)->postJson('/api/v1/auth/login', $u)->assertOk();
    $token = resetToken($this, $u['email']);

    // consumed from "another browser": this session's credential version is now stale
    expect(DB::connection('pgsql_auth')->selectOne('select auth_api.consume_password_reset(?, ?) as ok', [$token, '$argon2id$v=19$m=65536,t=3,p=1$c2FsdHNhbHQ$aGFzaGhhc2g'])->ok)->toBeTrue();
    spa($this)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
})->group('integration', 'auth');

it('T-RST: forgot answers 202 after the same minimum time and sends nothing for unknown or password-less emails', function () {
    $invited = IdentityFixtures::email();
    IdentityFixtures::invite($this->t, $invited); // identity exists but never set a password

    foreach (['nobody-'.bin2hex(random_bytes(4)).'@example.test', $invited] as $email) {
        expect(resetToken($this, $email))->toBe('');
        Mail::assertNotSent(PasswordResetMail::class);
    }
    $started = hrtime(true);
    spa($this)->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertStatus(202);
    expect((hrtime(true) - $started) / 1e6)->toBeGreaterThanOrEqual(400.0);
})->group('integration', 'auth');

it('T-RST: a reset clears an account lockout', function () {
    $u = activeUser($this, $this->t);
    $auth = DB::connection('pgsql_auth');
    $user = $auth->selectOne('select user_id from auth_api.login_lookup(?)', [$u['email']])->user_id;
    for ($i = 0; $i < 10; $i++) {
        $auth->select('select auth_api.record_login_failure(?, ?)', [$user, hash('sha256', "ip-{$i}")]);
    }
    expect($auth->selectOne('select status from auth_api.login_lookup(?)', [$u['email']])->status)->toBe('LOCKED');

    $new = 'a brand new passphrase 2026';
    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => resetToken($this, $u['email']), 'password' => $new, 'password_confirmation' => $new])->assertNoContent();
    spa($this)->postJson('/api/v1/auth/login', ['email' => $u['email'], 'password' => $new])->assertOk();
})->group('integration', 'auth');

it('D-B1-07: rejects short and breached passwords on reset and on invitation acceptance', function () {
    $u = activeUser($this, $this->t);
    $token = resetToken($this, $u['email']);

    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'Password1234', 'password_confirmation' => 'Password1234'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);

    $invite = invitationToken($this, $this->t, IdentityFixtures::email());
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $invite, 'password' => 'passwordpassword', 'password_confirmation' => 'passwordpassword'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);

    // the rejected attempts did not burn the reset link
    $new = 'a brand new passphrase 2026';
    spa($this)->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => $new, 'password_confirmation' => $new])->assertNoContent();
})->group('integration', 'auth');

it('T-RSTU-10: a forgot-password request throttled at HTTP gets the same delayed 202 and reaches nothing', function () {
    $u = activeUser($this, $this->t);
    for ($i = 0; $i < 3; $i++) {
        spa($this)->postJson('/api/v1/auth/password/forgot', ['email' => $u['email']])->assertStatus(202)->assertHeaderMissing('X-RateLimit-Remaining');
    }
    $this->artisan('eora:deliver-invitations', ['--once' => true])->assertSuccessful(); // drain the accepted ones

    $started = hrtime(true);
    $throttled = spa($this)->postJson('/api/v1/auth/password/forgot', ['email' => $u['email']]);
    $throttled->assertStatus(202)->assertNoContent(202);
    expect((hrtime(true) - $started) / 1e6)->toBeGreaterThanOrEqual(400.0)
        ->and($throttled->headers->has('Retry-After'))->toBeFalse()
        ->and($throttled->headers->has('X-RateLimit-Remaining'))->toBeFalse();

    Mail::fake();
    $this->artisan('eora:deliver-invitations', ['--once' => true])->assertSuccessful();
    Mail::assertNotSent(PasswordResetMail::class);
})->group('integration', 'auth');
