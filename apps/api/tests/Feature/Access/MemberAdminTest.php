<?php

declare(strict_types=1);

use App\Platform\Auth\Delivery\InvitationMail;
use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AccessFixtures;
use Tests\Support\IdentityFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

/*
 * M1 — Administration → Users (doc 28 §1, doc 25 §2.4, §13; D-068). Invitation through the real delivery worker,
 * resend, suspend and reactivate with SoD-06 and scope coverage.
 */

/** Runs the delivery worker once and returns the invitation tokens mailed to $email, oldest first. */
function mailedTokens(TestCase $test, string $email): array
{
    Mail::fake();
    $test->artisan('eora:deliver-invitations', ['--once' => true])->assertSuccessful();

    return Mail::sent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo($email))
        ->map(fn (InvitationMail $m) => preg_match('/#token=([0-9a-f]{64})$/', $m->link, $x) === 1 ? $x[1] : null)
        ->filter()->values()->all();
}

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->admin = member($this, $this->t);
    AccessFixtures::grant($this->t, $this->admin['id'], 'TENANT_ADMIN');
    RateLimiter::clear(md5('member-invite'.'member-invite:'.$this->admin['id']));
});

it('invites by e-mail: INVITED member, mail from the worker, acceptance activates', function () {
    $email = IdentityFixtures::email();
    signIn($this, $this->admin, $this->t);

    $membership = spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $email])->assertCreated()
        ->assertJsonPath('data.status', 'INVITED')->assertJsonPath('data.email', $email)->assertJsonPath('data.assignments', [])
        ->json('data.membershipId');

    $tokens = mailedTokens($this, $email);
    expect($tokens)->toHaveCount(1);
    $password = 'correct horse battery staple';
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $tokens[0], 'password' => $password, 'password_confirmation' => $password])->assertNoContent();

    signIn($this, $this->admin, $this->t);
    $members = collect(spa($this)->getJson('/api/v1/core/members')->json('data'))->keyBy('membershipId');
    expect($members[$membership]['status'])->toBe('ACTIVE');
})->group('integration', 'rbac');

it('refuses to invite an existing member and rejects malformed e-mails', function () {
    $m = member($this, $this->t);
    signIn($this, $this->admin, $this->t);

    spa($this)->postJson('/api/v1/core/members/invitations', ['email' => strtoupper($m['email'])])->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    foreach (['not-an-email', 'a@b.c', 'x@localhost'] as $bad) {
        spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $bad])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
})->group('integration', 'rbac');

it('invites an identity that already belongs to another tenant without touching its password', function () {
    $b = TenantFixtures::provision(1);
    $elsewhere = member($this, $b);
    signIn($this, $this->admin, $this->t);

    spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $elsewhere['email']])->assertCreated()
        ->assertJsonPath('data.userId', $elsewhere['id']);
    spa($this)->postJson('/api/v1/auth/login', ['email' => $elsewhere['email'], 'password' => $elsewhere['password']])->assertOk();
})->group('integration', 'rbac');

it('resends a pending invitation: new link, the previous one stops working', function () {
    $email = IdentityFixtures::email();
    signIn($this, $this->admin, $this->t);
    $membership = spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $email])->json('data.membershipId');
    $first = mailedTokens($this, $email)[0];

    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/resend-invitation")->assertStatus(202);
    $second = mailedTokens($this, $email)[0];
    expect($second)->not->toBe($first);

    $body = ['password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple'];
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $first] + $body)->assertStatus(422);
    spa($this)->postJson('/api/v1/auth/invitations/accept', ['token' => $second] + $body)->assertNoContent();

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/resend-invitation")->assertStatus(409);
})->group('integration', 'rbac');

it('suspends a member: its next request fails, reactivation restores access; assignments are kept', function () {
    $m = member($this, $this->t);
    AccessFixtures::grant($this->t, $m['id'], 'PPC', ['PLANT', $this->t['plants'][0]]);
    signIn($this, $m, $this->t);
    spa($this)->getJson('/api/v1/auth/me')->assertOk();

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$m['membership']}/actions/suspend")->assertOk()
        ->assertJsonPath('data.status', 'SUSPENDED')->assertJsonPath('data.assignments.0.roleCode', 'PPC');
    spa($this)->postJson("/api/v1/core/members/{$m['membership']}/actions/suspend")->assertStatus(409);

    spa($this)->postJson('/api/v1/auth/login', ['email' => $m['email'], 'password' => $m['password']])->assertOk()->assertJsonCount(0, 'tenants');

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$m['membership']}/actions/reactivate")->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    signIn($this, $m, $this->t);
    expect(spa($this)->getJson('/api/v1/auth/me')->json('permissions'))->toContain('planning.wo.view');
})->group('integration', 'rbac');

it('reactivates a suspended invitation back to INVITED', function () {
    $email = IdentityFixtures::email();
    signIn($this, $this->admin, $this->t);
    $membership = spa($this)->postJson('/api/v1/core/members/invitations', ['email' => $email])->json('data.membershipId');

    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/suspend")->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
    spa($this)->postJson("/api/v1/core/members/{$membership}/actions/reactivate")->assertOk()->assertJsonPath('data.status', 'INVITED');
})->group('integration', 'rbac');

it('SoD-06 and D-068: no self-suspension; a plant administrator cannot suspend a tenant-wide member', function () {
    $plantAdmin = member($this, $this->t);
    AccessFixtures::grant($this->t, $plantAdmin['id'], 'TENANT_ADMIN', ['PLANT', $this->t['plants'][0]]);
    $local = member($this, $this->t);
    AccessFixtures::grant($this->t, $local['id'], 'QCI', ['PLANT', $this->t['plants'][0]]);

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$this->admin['membership']}/actions/suspend")->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    signIn($this, $plantAdmin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$this->admin['membership']}/actions/suspend")->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    spa($this)->postJson("/api/v1/core/members/{$local['membership']}/actions/suspend")->assertOk();

    $status = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select status from memberships where id = ?', [$this->admin['membership']])->status);
    expect($status)->toBe('ACTIVE');
})->group('integration', 'rbac');

it('requires core.users_roles permissions; foreign or malformed member ids are 404', function () {
    $m = member($this, $this->t);
    $b = TenantFixtures::provision(1);
    $foreign = member($this, $b);

    signIn($this, $m, $this->t);
    spa($this)->postJson('/api/v1/core/members/invitations', ['email' => IdentityFixtures::email()])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    spa($this)->postJson("/api/v1/core/members/{$this->admin['membership']}/actions/suspend")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

    signIn($this, $this->admin, $this->t);
    spa($this)->postJson("/api/v1/core/members/{$foreign['membership']}/actions/suspend")->assertNotFound();
    spa($this)->postJson('/api/v1/core/members/not-a-uuid/actions/reactivate')->assertNotFound();
})->group('integration', 'rbac');

it('limits invitations per administrator only when a cap is configured', function () {
    config(['eora.member_invite_per_hour' => 30]);
    signIn($this, $this->admin, $this->t);
    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit(md5('member-invite'.'member-invite:'.$this->admin['id']), 3600); // ThrottleRequests key for a named limiter
    }

    spa($this)->postJson('/api/v1/core/members/invitations', ['email' => IdentityFixtures::email()])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

    config(['eora.member_invite_per_hour' => null]);
    spa($this)->postJson('/api/v1/core/members/invitations', ['email' => IdentityFixtures::email()])->assertCreated();
})->group('integration', 'rbac');
