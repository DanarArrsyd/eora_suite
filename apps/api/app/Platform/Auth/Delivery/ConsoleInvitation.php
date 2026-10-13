<?php

declare(strict_types=1);

namespace App\Platform\Auth\Delivery;

use Illuminate\Support\Facades\DB;

/**
 * Console channel for the first administrator (doc 25 §2.4, D-B1-18): runs the delivery-worker steps for one
 * membership with the eora_auth_delivery role and returns the one-time link for the operator's terminal. The caller
 * prints it; it must never be logged. Returns null when there is no open console delivery (e.g. send cap reached,
 * invitation already accepted).
 */
final class ConsoleInvitation
{
    private const int MAX_ROUNDS = 50;

    public function deliver(string $membershipId): ?string
    {
        $db = DB::connection('pgsql_delivery');
        $worker = 'console:'.substr(gethostname() ?: 'host', 0, 40).':'.getmypid();

        // pending requests are converted oldest first across all tenants: keep converting until this membership's console
        // delivery exists (bounded; each round converts up to 100 requests)
        $claim = null;
        for ($round = 0; $round < self::MAX_ROUNDS && ($claim->delivery_id ?? null) === null; $round++) {
            $db->select('select auth_api.claim_invitation_dispatch(?, ?)', [$worker, 100]);
            $claim = $db->selectOne('select * from auth_api.claim_console_delivery(?, ?)', [$worker, $membershipId]);
        }
        if ($claim === null || $claim->delivery_id === null) {
            return null;
        }

        $secret = $db->selectOne('select auth_api.issue_invitation_secret(?, ?) as s', [$claim->delivery_id, $claim->claim_token])->s;
        if ($secret === null) {
            return null;
        }
        // shown to the operator now: acknowledge so the secret becomes usable (SENT)
        $db->select('select auth_api.mark_delivery_result(?, ?, true, null)', [$claim->delivery_id, $claim->claim_token]);

        return rtrim((string) config('eora.frontend_url'), '/').'/invitations/accept#token='.$secret;
    }
}
