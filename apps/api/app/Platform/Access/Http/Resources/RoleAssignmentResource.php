<?php

declare(strict_types=1);

namespace App\Platform\Access\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * A role assignment with its scope (doc 18, D-B1-02). `effective` = unrevoked and inside its validity window now.
 *
 * @property stdClass $resource
 */
final class RoleAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => (string) $a->id,
            'membershipId' => (string) $a->membership_id,
            'roleId' => (string) $a->role_id,
            'roleCode' => (string) $a->role_code,
            /** @var 'TENANT'|'COMPANY'|'PLANT' */
            'scopeType' => (string) $a->scope_type,
            'companyId' => $a->company_id === null ? null : (string) $a->company_id,
            'plantId' => $a->plant_id === null ? null : (string) $a->plant_id,
            'validFrom' => (string) $a->valid_from,
            'validUntil' => $a->valid_until === null ? null : (string) $a->valid_until,
            'grantedBy' => (string) $a->granted_by,
            'grantReason' => (string) $a->grant_reason,
            'revokedAt' => $a->revoked_at === null ? null : (string) $a->revoked_at,
            'revokeReason' => $a->revoke_reason === null ? null : (string) $a->revoke_reason,
            'effective' => (bool) $a->effective,
        ];
    }
}
