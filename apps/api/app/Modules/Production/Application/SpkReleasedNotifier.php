<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Outbox\OutboxConsumer;
use DateTimeImmutable;
use stdClass;

/**
 * SPK per Line (doc 38, D-138): when an SPK is released, everyone who opens production runs at its plant (holders of
 * `production.run.create` and `production.spk.view` in scopes covering the plant), except the releaser, gets one inbox notice with a link to the
 * SPK. Runs from the outbox relay, once per event (processed_events); only `production.spk_released` from the app is read.
 */
final class SpkReleasedNotifier implements OutboxConsumer
{
    public function name(): string
    {
        return 'spk_released_notifier';
    }

    public function handle(EoraPostgresConnection $db, stdClass $event): void
    {
        if ($event->source !== 'app' || $event->event_type !== 'production.spk_released') {
            return;
        }
        // the SPK as it is now, read under RLS in the event's tenant (a later revision does not change what this one was)
        $spk = $db->selectOne(
            'select h.id, h.plant_id, h.spk_no, h.revision, h.production_date::text as production_date, h.released_by, wc.code as line_code
               from spk_headers h join work_centers wc on wc.id = h.work_center_id where h.id = ?',
            [(string) $event->aggregate_id],
        );
        if ($spk === null || $spk->spk_no === null) {
            return;
        }
        $title = mb_substr("SPK released: {$spk->spk_no} · {$spk->line_code} · ".(new DateTimeImmutable((string) $spk->production_date))->format('d M Y'), 0, 200);
        $body = (int) $spk->revision > 1 ? "Revision {$spk->revision} replaces the earlier SPK. Check the changed lines before you start." : 'The planner released the plan for this line and day.';
        // recipients hold both opening runs and reading SPKs in a scope covering the plant: the deep link must open for them
        $db->affectingStatement(
            "insert into inbox_notifications (tenant_id, membership_id, user_id, event_id, kind, severity, action_required, title, body, deep_link)
             select m.tenant_id, m.id, m.user_id, ?::uuid, 'production.spk_released', 'INFO', false, ?, ?, ?
               from memberships m
               join role_assignments ra on ra.membership_id = m.id and ra.revoked_at is null and ra.valid_from <= now() and (ra.valid_until is null or ra.valid_until > now())
               join role_permissions rp on rp.role_id = ra.role_id and rp.permission_code in ('production.run.create', 'production.spk.view')
              where m.status = 'ACTIVE' and m.user_id is distinct from ?::uuid
                and (ra.scope_type = 'TENANT' or (ra.scope_type = 'PLANT' and ra.plant_id = ?::uuid)
                     or (ra.scope_type = 'COMPANY' and ra.company_id = (select p.company_id from plants p where p.id = ?::uuid)))
              group by m.tenant_id, m.id, m.user_id
             having count(distinct rp.permission_code) = 2",
            [(string) $event->id, $title, $body, '/production/spk-line?spk='.$spk->id, $spk->released_by, $spk->plant_id, $spk->plant_id],
        );
    }
}
