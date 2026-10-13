<?php

declare(strict_types=1);

namespace App\Platform\Inbox\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * One inbox notification of the signed-in user (doc 07).
 *
 * @property stdClass $resource
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $n = $this->resource;

        return [
            'id' => (string) $n->id,
            'kind' => (string) $n->kind,
            /** @var 'INFO'|'WARNING'|'CRITICAL' */
            'severity' => (string) $n->severity,
            'actionRequired' => (bool) $n->action_required,
            'title' => (string) $n->title,
            'body' => $n->body === null ? null : (string) $n->body,
            'deepLink' => $n->deep_link === null ? null : (string) $n->deep_link,
            'createdAt' => (string) $n->created_at,
            'readAt' => $n->read_at === null ? null : (string) $n->read_at,
        ];
    }
}
