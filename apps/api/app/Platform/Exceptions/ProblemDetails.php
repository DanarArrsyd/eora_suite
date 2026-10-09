<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * RFC 9457 problem+json rendering for /api/* (doc 24 §5, ADR-0013).
 * Internal details never leak: 5xx responses carry a generic message plus a reference for support.
 */
final class ProblemDetails
{
    public const TYPE_BASE = 'https://errors.eora.app/';

    /** Policy reasons are never echoed: they can reveal permission names, scopes or other tenants' records. */
    private const array FORBIDDEN = ['status' => 403, 'code' => 'FORBIDDEN', 'title' => 'Forbidden', 'detail' => null];

    /**
     * @return array{status: int, code: string, title: string, detail: string|null, errors?: array<string, array<int, string>>}
     */
    public static function describe(Throwable $e, bool $debug): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                'status' => 422, 'code' => 'VALIDATION_FAILED', 'title' => 'Validation failed',
                'detail' => 'One or more fields are invalid.', 'errors' => $e->errors(),
            ],
            $e instanceof AuthenticationException => ['status' => 401, 'code' => 'UNAUTHENTICATED', 'title' => 'Authentication required', 'detail' => null],
            // Laravel prepares AuthorizationException as AccessDeniedHttpException (or a plain HttpException for
            // denyWithStatus/denyAsNotFound) before this renderer runs; the status mapping below covers both.
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::FORBIDDEN,
            $e instanceof ThrottleRequestsException => ['status' => 429, 'code' => 'RATE_LIMITED', 'title' => 'Too many requests', 'detail' => null],
            $e instanceof NotFoundHttpException => ['status' => 404, 'code' => 'NOT_FOUND', 'title' => 'Not found', 'detail' => null],
            $e instanceof MethodNotAllowedHttpException => ['status' => 405, 'code' => 'METHOD_NOT_ALLOWED', 'title' => 'Method not allowed', 'detail' => null],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => match ($e->getStatusCode()) {
                401 => ['status' => 401, 'code' => 'UNAUTHENTICATED', 'title' => 'Authentication required', 'detail' => null],
                403 => self::FORBIDDEN,
                404 => ['status' => 404, 'code' => 'NOT_FOUND', 'title' => 'Not found', 'detail' => null],
                default => ['status' => $e->getStatusCode(), 'code' => 'MALFORMED_REQUEST', 'title' => 'Request rejected', 'detail' => null],
            },
            default => [
                'status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                'code' => 'INTERNAL_ERROR',
                'title' => 'Internal error',
                'detail' => $debug ? $e->getMessage() : 'The request could not be completed. Nothing was changed.',
            ],
        };
    }

    public static function render(Throwable $e, bool $debug): JsonResponse
    {
        $p = self::describe($e, $debug);
        $correlationId = (string) (Context::get('correlation_id') ?? '');

        $body = array_filter([
            'type' => self::TYPE_BASE.$p['code'],
            'title' => $p['title'],
            'status' => $p['status'],
            'code' => $p['code'],
            'detail' => $p['detail'],
            'correlationId' => $correlationId !== '' ? $correlationId : null,
            'reference' => self::reference($correlationId),
            'errors' => $p['errors'] ?? null,
        ], static fn ($v) => $v !== null);

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($body, $p['status'], [...$headers, 'Content-Type' => 'application/problem+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Short, user-quotable reference derived from the correlation id (e.g. "q4zt-8m2k"). */
    public static function reference(string $correlationId): ?string
    {
        if ($correlationId === '') {
            return null;
        }
        $tail = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', $correlationId) ?? '', -8));

        return strlen($tail) === 8 ? substr($tail, 0, 4).'-'.substr($tail, 4) : null;
    }
}
