<?php

declare(strict_types=1);

namespace App\Platform\Security;

use InvalidArgumentException;

/**
 * One security event (doc 25 §8). Never carries request bodies, passwords, tokens, cookies or other tenants' data:
 * identifiers only, and client IP / user agent as keyed hashes.
 */
final readonly class SecurityEvent
{
    public function __construct(
        public string $action,
        public ?string $resource = null,
        public ?string $requestedId = null,
        public ?string $route = null,
        public ?int $httpStatus = null,
        public ?string $correlationId = null,
        public ?string $ipHash = null,
        public ?string $userAgentHash = null,
        public ?string $detailCode = null,
    ) {
        if (preg_match('/^[a-z][a-z_]*(\.[a-z_]+)+$/', $action) !== 1 || str_starts_with($action, 'auth.')) {
            throw new InvalidArgumentException('Invalid or reserved security event action.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;

        return new self(
            action: (string) $string('action'),
            resource: $string('resource'),
            requestedId: $string('requestedId'),
            route: $string('route'),
            httpStatus: isset($data['httpStatus']) && is_int($data['httpStatus']) ? $data['httpStatus'] : null,
            correlationId: $string('correlationId'),
            ipHash: $string('ipHash'),
            userAgentHash: $string('userAgentHash'),
            detailCode: $string('detailCode'),
        );
    }

    /** @return array<string, mixed> */
    public function toLog(): array
    {
        return array_filter([
            'action' => $this->action,
            'resource' => $this->resource,
            'requested_id' => $this->requestedId,
            'route' => $this->route,
            'http_status' => $this->httpStatus,
            'correlation_id' => $this->correlationId,
            'detail_code' => $this->detailCode,
        ], static fn ($v) => $v !== null);
    }
}
