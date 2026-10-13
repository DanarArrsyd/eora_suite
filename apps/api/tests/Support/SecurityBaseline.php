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
     * Exact allowlist of SECURITY DEFINER functions: full signature (regprocedure text) => owner and the complete,
     * sorted set of roles holding EXECUTE (the owner itself excluded; "PUBLIC" would be listed by name).
     *
     * @var array<string, array{owner: string, execute: list<string>}>
     */
    public const array DEFINER_FUNCTIONS = [
        // signed tenant context verifier — D-055, ADR-0001 Amendment 2, doc 25 §5.5 (reviewed in the D-B1-24 PoC)
        'ctx_api.context()' => ['owner' => 'eora_ctx_verifier', 'execute' => ['eora_app']],
        // identity and credential surface — doc 25 §2.8 (one grantee each)
        'auth_api.cleanup_orphan_identities(integer)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.consume_password_reset(text,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.issue_reset_secret(uuid,uuid)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.request_password_reset(text,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.claim_delivery(text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        // M1 step 6: first-administrator link to the operator's terminal (doc 25 §2.4, D-B1-18)
        'auth_api.claim_console_delivery(text,uuid)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.claim_invitation_dispatch(text,integer)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.consume_invitation(text,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.invite_identity(text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.issue_invitation_secret(uuid,uuid)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.login_lookup(text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.mark_delivery_result(uuid,uuid,boolean,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_delivery']],
        'auth_api.provision_identity(text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_provisioner']],
        'auth_api.record_login_failure(uuid,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        'auth_api.record_security_event(text,text,text,text,text,text)' => ['owner' => 'eora_auth_definer', 'execute' => ['eora_auth_web']],
        // provisioning registry — doc 25 §2.5 (M1 step 6), one grantee
        'prov_api.begin_provisioning(text,text,text)' => ['owner' => 'eora_prov_definer', 'execute' => ['eora_provisioner']],
        'prov_api.lock_request(text)' => ['owner' => 'eora_prov_definer', 'execute' => ['eora_provisioner']],
        'prov_api.mark_tenant_created(text,uuid,uuid)' => ['owner' => 'eora_prov_definer', 'execute' => ['eora_provisioner']],
        'prov_api.provision_lookup(text,text)' => ['owner' => 'eora_prov_definer', 'execute' => ['eora_provisioner']],
        // outbox relay surface — doc 25 §6.4 (M1 step 7b), R-30/R-33
        'outbox_api.claim_events(text,integer)' => ['owner' => 'eora_outbox_definer', 'execute' => ['eora_relay']],
        'outbox_api.complete_event(uuid,uuid)' => ['owner' => 'eora_outbox_definer', 'execute' => ['eora_relay']],
        'outbox_api.fail_event(uuid,uuid,text)' => ['owner' => 'eora_outbox_definer', 'execute' => ['eora_relay']],
    ];

    /** Every RLS expression on a tenant table must read the signed context (ADR-0001 Amendment 2). */
    public const string TENANT_CONTEXT_CALL = 'ctx_api.context()';

    /**
     * Reviewed policies on tenant tables whose expressions intentionally differ from tenant_id = signed tenant
     * (e.g. a picker policy). Key "table.policy" => review reference.
     *
     * @var array<string, string>
     */
    public const array TENANT_POLICY_EXCEPTIONS = [
        'memberships.picker_read' => 'doc 25 §2.6 P2: tenant-less picker context reads the actor\'s own memberships',
        'memberships.definer_read' => 'doc 25 §2.1: eora_auth_definer reads membership status to validate invitations (NOLOGIN, reviewed functions only)',
        'invitation_dispatch_requests.definer_dispatch' => 'doc 25 §2.4 R-40: delivery definer converts pending dispatch requests',
        'invitation_dispatch_requests.definer_dispatch_update' => 'doc 25 §2.4 R-40: delivery definer sets the request outcome',
        'outbox_events.definer_insert' => 'doc 25 §2.3 R-26: consume_password_reset inserts auth.password_reset_consumed from auth_web only',
        'outbox_events.relay_claim_read' => 'doc 25 §6.4 R-30/R-33: outbox definer reads ids, tenant ids and claim columns only (column grants)',
        'outbox_events.relay_claim_update' => 'doc 25 §6.4 R-33: outbox definer writes claim columns only (column grants)',
        'security_events.outbox_definer_insert' => 'doc 25 §6.4: claim_events records outbox.dead for exhausted events (fixed action/resource, INSERT only)',
        'security_events.definer_insert' => 'doc 25 §8 R-19: auth definer functions insert auth-source events from stored rows',
    ];

    /** Schemas whose objects are reachable only through explicit grants (no PUBLIC privilege at all). */
    public const array PRIVATE_SCHEMAS = ['public', 'ext', 'auth', 'auth_api', 'prov', 'prov_api', 'outbox_api', 'ctx', 'ctx_api'];
}
