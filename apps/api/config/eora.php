<?php

declare(strict_types=1);

return [
    /*
     * Signed tenant context (D-055, ADR-0001 Amendment 2, doc 25 §5.5). The API signs a transaction-bound token with
     * this key; PostgreSQL verifies it in ctx_api.context() with the same key (loaded by the database bootstrap).
     * Never logged, never sent to the client.
     */
    'context' => [
        'kid' => env('EORA_CTX_SIGNING_KID', 'kid1'),
        'key' => env('EORA_CTX_SIGNING_KEY'),
        // token lifetime in seconds; must cover the longest request/job transaction (D-B1-11: jobs up to 120 s)
        'ttl' => (int) env('EORA_CTX_TTL', 300),
    ],

    // Server pepper for keyed hashes (ip_hash, user_agent_hash, email_hmac; D-B1-22): 64 hex chars from the secrets
    // manager; rotated yearly (old key kept for lookups when rotation is implemented).
    'pepper' => [
        'key' => env('EORA_PEPPER_KEY'),
    ],
];
