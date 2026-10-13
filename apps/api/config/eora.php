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

    // Public URL of the web app; invitation links point here (the secret travels in the URL fragment).
    'frontend_url' => env('EORA_FRONTEND_URL', env('APP_URL', 'http://localhost:3000')),

    // Server pepper for keyed hashes (ip_hash, user_agent_hash, email_hmac; D-B1-22): 64 hex chars from the secrets
    // manager; rotated yearly (old key kept for lookups when rotation is implemented).
    'pepper' => [
        'key' => env('EORA_PEPPER_KEY'),
    ],

    // Optional per-administrator cap on member invitations + resends per hour (doc 25 §2.4: per-inviter limits live at
    // the HTTP layer). No value is approved yet, so it is off unless set; the database still caps identity creation
    // platform-wide and resends at 5 per membership per 24 h.
    // Reverse proxies / load balancers in front of the API (ADR-0006), exact IPs or CIDRs, comma-separated. Only these hops
    // may set X-Forwarded-*; the client IP used for rate limits and security events is resolved through them. Empty =
    // trust none (the direct peer is the client). Never "*" in production.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('EORA_TRUSTED_PROXIES', ''))))),

    // D-B1-06: factory NAT addresses/CIDRs that get the higher per-IP login limit (60/min instead of 20/min), e.g.
    // "203.0.113.10,198.51.100.0/24". Empty by default.
    'login_nat_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('EORA_LOGIN_NAT_ALLOWLIST', ''))))),

    'member_invite_per_hour' => env('EORA_MEMBER_INVITE_PER_HOUR') === null ? null : (int) env('EORA_MEMBER_INVITE_PER_HOUR'),
];
