<?php

declare(strict_types=1);

use App\Platform\Auth\Delivery\InvitationMail;
use App\Platform\Auth\Delivery\PasswordResetMail;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IdentityFixtures;
use Tests\TestCase;

/*
 * Group B HTTP helpers shared by the feature tests (doc 25 §11): a stateful SPA request from the app's own origin and
 * an invited, accepted user driven through the real delivery worker.
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

/** Requests a reset for $email over HTTP and returns the secret from the email the worker sent ('' when none was sent). */
function resetToken(TestCase $test, string $email): string
{
    Mail::fake();
    spa($test)->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertStatus(202);
    $test->artisan('eora:deliver-invitations', ['--once' => true])->assertSuccessful();
    $token = '';
    foreach (Mail::sent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo($email)) as $mail) {
        preg_match('/\/password\/reset#token=([0-9a-f]{64})$/', $mail->link, $m) === 1 && $token = $m[1];
    }

    return $token;
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
