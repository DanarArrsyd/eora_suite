<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Reviewed security baseline for the schema guard (doc 25 §5.1, §5.4; D-056 P-04). Changing a list here is a
 * security review item: every entry needs its review reference in the pull request.
 */
final class SecurityBaseline
{
    /** LOGIN roles that run requests, jobs or auth code; never superuser, never BYPASSRLS, own nothing. */
    public const array RUNTIME_ROLES = ['eora_app', 'eora_auth_web', 'eora_auth_delivery', 'eora_provisioner', 'eora_relay'];

    /** NOLOGIN owners of reviewed definer code; nobody may be a member of them. */
    public const array DEFINER_OWNERS = ['eora_auth_definer', 'eora_prov_definer', 'eora_outbox_definer', 'eora_ctx_verifier'];

    public const string MIGRATIONS_OWNER = 'eora_owner';

    /**
     * Exact allowlist of SECURITY DEFINER functions as "schema.name" (sorted). Empty until the reviewed definer
     * functions of later B1 steps are added (signed context, auth, provisioning, outbox).
     *
     * @var list<string>
     */
    public const array DEFINER_FUNCTIONS = [];

    /** Schemas whose objects are reachable only through explicit grants (no PUBLIC privilege at all). */
    public const array PRIVATE_SCHEMAS = ['public', 'ext', 'auth', 'auth_api', 'prov', 'prov_api', 'outbox_api', 'ctx', 'ctx_api'];
}
