<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency for edits (doc 24 §4): `PATCH` carries `If-Match: "<version>"` with the row version the
 * client read; the update applies only to that version. Missing → 428 PRECONDITION_REQUIRED; another version
 * committed in between → 412 VERSION_CONFLICT. Responses carry the new version as `ETag`.
 */
final class Concurrency
{
    public static function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if (preg_match('/^(?:W\/)?"(\d{1,9})"$/', $header, $m) !== 1) {
            throw new DomainProblem(428, 'PRECONDITION_REQUIRED', 'Version required', 'Send If-Match with the version you edited, e.g. If-Match: "3".');
        }

        return (int) $m[1];
    }

    public static function conflict(): DomainProblem
    {
        return new DomainProblem(412, 'VERSION_CONFLICT', 'Changed by someone else', 'This record was changed after you opened it. Reload it and apply your change again.');
    }

    public static function withVersion(JsonResponse $response, int $version): JsonResponse
    {
        $response->headers->set('ETag', '"'.$version.'"');

        return $response;
    }
}
