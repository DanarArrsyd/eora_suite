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
        $unread = (int) $db->selectOne('select count(*) as n from inbox_notifications where read_at is null')->n;

        return NotificationResource::collection($rows)->additional(['meta' => ['unread' => $unread]])->response();
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
