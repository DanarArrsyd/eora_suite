<?php

declare(strict_types=1);

namespace App\Platform\Auth\Delivery;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Secret-delivery worker (doc 25 §2.4, §2.9, D-B1-18). Runs as its own process with the eora_auth_delivery role only:
 * converts pending invitation requests, claims one delivery at a time under a lease, issues the secret, sends the email
 * outside any transaction, then acknowledges the result for the current claim. Secrets never reach logs.
 */
final class DeliverInvitations extends Command
{
    protected $signature = 'eora:deliver-invitations {--once : Process what is due, then exit} {--sleep=5 : Seconds between polls}';

    protected $description = 'Deliver pending invitation and password-reset emails and remove orphan identities (separate worker, eora_auth_delivery role)';

    private const int CLEANUP_EVERY_SECONDS = 600;

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment('testing')) {
            $this->error("Refusing to deliver secrets through the '{$mailer}' mailer (it would store them).");

            return self::FAILURE;
        }

        $worker = substr(gethostname() ?: 'worker', 0, 40).':'.getmypid();
        $db = DB::connection('pgsql_delivery');

        $lastCleanup = 0;

        do {
            if (time() - $lastCleanup >= self::CLEANUP_EVERY_SECONDS) {
                // doc 25 §2.4 (R-25): password-less identities without membership older than 7 days
                $db->select('select auth_api.cleanup_orphan_identities(?)', [200]);
                $lastCleanup = time();
            }
            $db->select('select auth_api.claim_invitation_dispatch(?, ?)', [$worker, 50]);

            while (($claim = $db->selectOne('select * from auth_api.claim_delivery(?)', [$worker])) !== null && $claim->delivery_id !== null) {
                $this->deliver($db, $claim);
            }

            if (! $this->option('once')) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function deliver(Connection $db, \stdClass $claim): void
    {
        $reset = $claim->kind === 'RESET';
        $function = $reset ? 'auth_api.issue_reset_secret' : 'auth_api.issue_invitation_secret';
        $secret = $db->selectOne("select {$function}(?, ?) as s", [$claim->delivery_id, $claim->claim_token])->s;
        if ($secret === null) {
            return; // target gone: the database cancelled the delivery
        }

        $base = rtrim((string) config('eora.frontend_url'), '/');
        $mail = $reset
            ? new PasswordResetMail($base.'/password/reset#token='.$secret)
            : new InvitationMail($base.'/invitations/accept#token='.$secret);

        try {
            Mail::to((string) $claim->recipient)->send($mail);
            $ok = true;
        } catch (Throwable $e) {
            Log::warning('invitation_delivery_failed', ['delivery_id' => $claim->delivery_id, 'error' => class_basename($e)]);
            $ok = false;
        }

        try {
            $db->select('select auth_api.mark_delivery_result(?, ?, ?, ?)', [$claim->delivery_id, $claim->claim_token, $ok, $ok ? null : 'SEND_FAILED']);
        } catch (Throwable $e) {
            // lease expired or a newer claim exists: the next claim re-issues a fresh secret (doc 25 §2.9)
            Log::warning('invitation_ack_rejected', ['delivery_id' => $claim->delivery_id, 'error' => class_basename($e)]);
        }
    }
}
