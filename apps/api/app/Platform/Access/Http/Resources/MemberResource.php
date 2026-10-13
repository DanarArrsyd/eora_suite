<?php

declare(strict_types=1);

namespace App\Platform\Access\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * A member of the active tenant with its unrevoked role assignments (`assignments` is set by the controller).
 *
 * @property stdClass $resource
 */
final class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'membershipId' => (string) $m->id,
            'userId' => (string) $m->user_id,
            'displayName' => (string) $m->display_name,
            'email' => (string) $m->email_display,
            /** @var 'INVITED'|'ACTIVE'|'SUSPENDED'|'REVOKED' */
            'status' => (string) $m->status,
            'assignments' => RoleAssignmentResource::collection($m->assignments),
        ];
    }
}
