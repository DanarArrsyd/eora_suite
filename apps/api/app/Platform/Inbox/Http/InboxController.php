<?php

declare(strict_types=1);

namespace App\Platform\Inbox\Http;

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The signed-in user's inbox in the active tenant (doc 07; doc 24 §7 `GET /api/v1/inbox`, `POST /inbox/read`).
 * Row-level security limits every query to the actor's own notifications.
 */
final class InboxController
{
    public function index(): JsonResponse
    {
        $db = $this->db();
        $rows = $db->select('select id, kind, severity, action_required, title, body, deep_link, created_at, read_at from inbox_notifications order by created_at desc, id desc limit 50');
        // totals over the whole inbox, not the latest-50 page: the bell badge and dot must not lose older items
        $totals = $db->selectOne('select count(*) filter (where read_at is null) as unread, count(*) filter (where action_required) as action_required from inbox_notifications');

        return response()->json([
            /** The latest 50 notifications, newest first. */
            'data' => NotificationResource::collection($rows),
            'meta' => [
                /** Unread notifications in the whole inbox. */
                'unread' => (int) $totals->unread,
                /** Notifications that need the user's action in the whole inbox; reading does not clear them (D-025). */
                'actionRequired' => (int) $totals->action_required,
            ],
        ]);
    }

    /** Marks the given notifications (or all, when `ids` is absent) as read; unknown ids are ignored. */
    public function markRead(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['uuid']])['ids'] ?? null;
        $db = $this->db();

        $updated = $ids === null
            ? $db->update('update inbox_notifications set read_at = now() where read_at is null')
            : $db->update('update inbox_notifications set read_at = now() where read_at is null and id = any(?::uuid[])', ['{'.implode(',', array_map('strtolower', $ids)).'}']);

        return response()->json(['meta' => ['updated' => $updated]]);
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('The inbox requires the EORA PostgreSQL connection.');
    }
}
