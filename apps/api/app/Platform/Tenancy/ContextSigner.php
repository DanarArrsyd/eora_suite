<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use RuntimeException;

/**
 * Signs the transaction-bound context token verified by ctx_api.context():
 * v1.<kid>.<tenant|->.<actor>.<backend pid>.<transaction start µs>.<exp>.<hmac-sha256 hex>
 */
final class ContextSigner
{
    private readonly string $key;

    public function __construct(private readonly string $kid, string $hexKey, private readonly int $ttlSeconds)
    {
        if (preg_match('/^[a-z0-9]{1,16}$/', $kid) !== 1) {
            throw new RuntimeException('Signed context: invalid key id.');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $hexKey) !== 1) {
            throw new RuntimeException('Signed context: EORA_CTX_SIGNING_KEY must be 64 lowercase hex characters.');
        }

        $this->key = (string) hex2bin($hexKey);
    }

    public function sign(TenantContext $context, int $backendPid, string $transactionStartMicros, ?int $now = null): string
    {
        if ($backendPid <= 0 || preg_match('/^\d{1,20}$/', $transactionStartMicros) !== 1) {
            throw new RuntimeException('Signed context: invalid transaction binding.');
        }

        $payload = implode('.', [
            'v1',
            $this->kid,
            $context->tenantId ?? '-',
            $context->actorId,
            (string) $backendPid,
            $transactionStartMicros,
            (string) (($now ?? time()) + $this->ttlSeconds),
        ]);

        return $payload.'.'.hash_hmac('sha256', $payload, $this->key);
    }
}
