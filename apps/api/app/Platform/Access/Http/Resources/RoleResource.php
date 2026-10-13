<?php

declare(strict_types=1);

namespace App\Platform\Access\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * A tenant role (copy of a doc 18 §1 template) with its permission codes.
 *
 * @property stdClass $resource
 */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => (string) $r->id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'templateCode' => $r->template_code === null ? null : (string) $r->template_code,
            /** Suggested scope when assigning; null = as assigned (APR). */
            'defaultScopeType' => $r->default_scope_type === null ? null : (string) $r->default_scope_type,
            /** @var list<string> */
            'permissions' => json_decode((string) $r->permissions, true),
        ];
    }
}
