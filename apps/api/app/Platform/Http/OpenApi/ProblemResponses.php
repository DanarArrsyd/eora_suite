<?php

declare(strict_types=1);

namespace App\Platform\Http\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as T;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Error contract in the OpenAPI document (doc 24 §5, ADR-0013): every error response of an /api/v1 operation is
 * RFC 9457 `application/problem+json` with the catalogued codes that endpoint can return. Scramble's inferred error
 * responses (Laravel's `{message}` shapes) are replaced. A route without an entry here fails the contract test, so a
 * new endpoint must declare its errors.
 */
final class ProblemResponses implements DocumentTransformer, OperationTransformer
{
    private const array TENANT_READ = [401 => ['UNAUTHENTICATED', 'SESSION_EXPIRED'], 403 => ['FORBIDDEN']];

    private const array TENANT_WRITE = [
        401 => ['UNAUTHENTICATED', 'SESSION_EXPIRED'], 419 => ['CSRF_TOKEN_MISMATCH'], 503 => ['RETRYABLE_CONFLICT'],
    ];

    private const array SCOPED_403 = [403 => ['FORBIDDEN', 'SCOPE_FORBIDDEN', 'SOD_VIOLATION']];

    private const array MASTER_403 = [403 => ['FORBIDDEN', 'SCOPE_FORBIDDEN']];

    /** Master-data edit (doc 24 §4): If-Match required (428), stale version (412). */
    private const array MASTER_EDIT = self::TENANT_WRITE + self::MASTER_403 + [
        404 => ['NOT_FOUND'], 412 => ['VERSION_CONFLICT'], 422 => ['VALIDATION_FAILED'], 428 => ['PRECONDITION_REQUIRED'],
    ];

    /**
     * Route name => status => error codes (doc 24 §5 catalogue).
     *
     * @var array<string, array<int, list<string>>>
     */
    public const array ERRORS = [
        'v1.health.live' => [],
        'v1.health.ready' => [], // 503 `degraded` is the documented readiness body, not a problem
        'v1.auth.login' => [401 => ['UNAUTHENTICATED'], 419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED'], 429 => ['RATE_LIMITED']],
        'v1.auth.logout' => [419 => ['CSRF_TOKEN_MISMATCH']],
        'v1.auth.me' => [401 => ['UNAUTHENTICATED', 'SESSION_EXPIRED']],
        'v1.auth.tenant' => [401 => ['UNAUTHENTICATED', 'SESSION_EXPIRED'], 404 => ['NOT_FOUND'], 419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED']],
        'v1.auth.plant' => [401 => ['UNAUTHENTICATED', 'SESSION_EXPIRED'], 404 => ['NOT_FOUND'], 419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED']],
        'v1.auth.invitations.accept' => [409 => ['INVALID_STATE_TRANSITION'], 419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED'], 429 => ['RATE_LIMITED']],
        'v1.auth.password.forgot' => [419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED']],
        'v1.auth.password.reset' => [419 => ['CSRF_TOKEN_MISMATCH'], 422 => ['VALIDATION_FAILED'], 429 => ['RATE_LIMITED']],
        // tenant routes answer 403 FORBIDDEN while no tenant is selected
        'v1.inbox.index' => self::TENANT_READ,
        'v1.inbox.read' => self::TENANT_WRITE + [403 => ['FORBIDDEN'], 422 => ['VALIDATION_FAILED']],
        'v1.core.roles.index' => self::TENANT_READ,
        'v1.core.members.index' => self::TENANT_READ,
        'v1.core.members.invite' => self::TENANT_WRITE + [403 => ['FORBIDDEN'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED'], 429 => ['RATE_LIMITED']],
        'v1.core.members.resend-invitation' => self::TENANT_WRITE + [403 => ['FORBIDDEN'], 404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 429 => ['RATE_LIMITED']],
        'v1.core.members.suspend' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.core.members.reactivate' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.core.role-assignments.store' => self::TENANT_WRITE + self::SCOPED_403 + [409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.core.role-assignments.revoke' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        // query resources (ADR-0008, doc 24 §3) — M2 step 1
        'v1.query.index' => self::TENANT_READ + [404 => ['NOT_FOUND'], 422 => ['FILTER_NOT_ALLOWED', 'VALIDATION_FAILED']],
        'v1.query.schema' => self::TENANT_READ + [404 => ['NOT_FOUND']],
        // Master Data → organisation (doc 29 §1.1)
        'v1.core.companies.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.companies.update' => self::MASTER_EDIT,
        'v1.core.plants.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.plants.update' => self::MASTER_EDIT,
        'v1.core.warehouses.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.warehouses.update' => self::MASTER_EDIT,
        'v1.core.locations.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.locations.update' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.core.work-centers.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.work-centers.update' => self::MASTER_EDIT,
        'v1.core.machines.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.machines.update' => self::MASTER_EDIT,
        'v1.core.dies.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.dies.update' => self::MASTER_EDIT,
        'v1.core.uoms.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.uoms.update' => self::MASTER_EDIT,
        'v1.core.items.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.items.update' => self::MASTER_EDIT,
        // creates (201) without If-Match, edits the existing specification at its version
        'v1.core.items.material-spec' => self::MASTER_EDIT,
        'v1.core.uom-conversions.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.die-items.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.die-items.update' => self::MASTER_EDIT,
        'v1.core.customers.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.customers.update' => self::MASTER_EDIT,
        'v1.core.suppliers.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.suppliers.update' => self::MASTER_EDIT,
        'v1.core.customer-items.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.core.customer-items.update' => self::MASTER_EDIT,
        // Master Data → routings (doc 29 §1.3)
        'v1.planning.process-types.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.planning.process-types.update' => self::MASTER_EDIT,
        'v1.planning.routings.store' => self::TENANT_WRITE + self::MASTER_403 + [422 => ['VALIDATION_FAILED']],
        'v1.planning.routings.update' => self::MASTER_EDIT,
        'v1.planning.routings.versions.store' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.routing-versions.show' => self::TENANT_READ + self::MASTER_403 + [404 => ['NOT_FOUND']],
        'v1.planning.routing-versions.update' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.routing-versions.operations' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.routing-versions.submit' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.routing-versions.withdraw' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.routing-versions.reject' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.routing-versions.reopen' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        // maker ≠ checker and the plant scope are decided by lifecycle_api.approve_routing_version
        'v1.planning.routing-versions.approve' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        // BOMs (doc 29 §1.4, D-078) and BOM–routing maps (D-079)
        'v1.planning.bom-versions.store' => self::TENANT_WRITE + self::MASTER_403 + [409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-versions.show' => self::TENANT_READ + [404 => ['NOT_FOUND']],
        'v1.planning.bom-versions.update' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-versions.lines' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-versions.submit' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-versions.withdraw' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-versions.reject' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-versions.reopen' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-versions.approve' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-routing-maps.store' => self::TENANT_WRITE + self::MASTER_403 + [409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-routing-maps.show' => self::TENANT_READ + self::MASTER_403 + [404 => ['NOT_FOUND']],
        'v1.planning.bom-routing-maps.lines' => self::MASTER_EDIT + [409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-routing-maps.submit' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-routing-maps.withdraw' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-routing-maps.reject' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
        'v1.planning.bom-routing-maps.reopen' => self::TENANT_WRITE + self::MASTER_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION']],
        'v1.planning.bom-routing-maps.approve' => self::TENANT_WRITE + self::SCOPED_403 + [404 => ['NOT_FOUND'], 409 => ['INVALID_STATE_TRANSITION'], 422 => ['VALIDATION_FAILED']],
    ];

    /** Laravel-shaped error components Scramble registers; replaced by the problem responses above. */
    private const array INFERRED_COMPONENTS = ['ValidationException', 'AuthenticationException', 'NotFoundHttpException', 'AuthorizationException', 'AccessDeniedHttpException'];

    public function handle(Operation|OpenApi $subject, RouteInfo|OpenApiContext $context): void
    {
        if ($subject instanceof OpenApi) {
            foreach (self::INFERRED_COMPONENTS as $name) {
                $subject->components->removeResponse($name);
            }

            return;
        }

        assert($context instanceof RouteInfo);
        $errors = self::ERRORS[(string) $context->route->getName()] ?? null;
        if ($errors === null) {
            return; // undeclared route: left as inferred; the contract test fails until it is declared
        }
        if ($errors === []) {
            return;
        }

        $subject->responses = array_values(array_filter(
            $subject->responses ?? [],
            static fn (Response|Reference $r) => ! ($r instanceof Reference || (int) $r->code >= 400),
        ));
        ksort($errors);
        foreach ($errors as $status => $codes) {
            $response = Response::make($status)
                ->setDescription(implode(', ', $codes))
                ->setContent('application/problem+json', Schema::fromType(self::problemType($codes, $status)));
            if (in_array($status, [429, 503], true)) {
                $response->addHeader('Retry-After', (new Header)->setDescription('Seconds to wait before retrying.')->setSchema(Schema::fromType(new T\IntegerType)));
            }
            $subject->addResponse($response);
        }
    }

    /** @param  list<string>  $codes */
    private static function problemType(array $codes, int $status): T\ObjectType
    {
        $type = (new T\ObjectType)
            ->addProperty('type', (new T\StringType)->format('uri'))
            ->addProperty('title', new T\StringType)
            ->addProperty('status', (new T\IntegerType)->enum([$status]))
            ->addProperty('code', (new T\StringType)->enum($codes))
            ->addProperty('detail', (new T\StringType)->setDescription('Safe to show; never names permissions, scopes or other tenants.'))
            ->addProperty('correlationId', new T\StringType)
            ->addProperty('reference', (new T\StringType)->setDescription('Short support reference derived from the correlation id.'));
        if ($status === 422) {
            $type->addProperty('errors', (new T\ObjectType)->additionalProperties((new T\ArrayType)->setItems(new T\StringType)));
        }

        return $type->setRequired(['type', 'title', 'status', 'code']);
    }
}
