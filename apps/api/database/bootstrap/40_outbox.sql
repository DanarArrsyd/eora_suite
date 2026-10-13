-- EORA outbox relay surface (doc 25 §6.4, R-30, R-33). Run by bootstrap.sh as the bootstrap superuser after
-- 30_prov.sql. Idempotent. Functions owned by the NOLOGIN eora_outbox_definer; EXECUTE only for eora_relay. They read
-- ids, tenant ids and claim columns of public.outbox_events (created by migration 000700 together with the definer's
-- column grants and policies) and return no payload.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET log_statement = 'none';

SET ROLE eora_outbox_definer;

-- Claims due events with a 2-minute lease; exhausted rows become DEAD first. Returns event id, tenant id, claim token.
CREATE OR REPLACE FUNCTION outbox_api.claim_events(worker_id text, batch_limit integer)
RETURNS TABLE (event_id uuid, tenant_id uuid, claim_token uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  IF worker_id IS NULL OR pg_catalog.length(worker_id) > 64 OR batch_limit NOT BETWEEN 1 AND 500 THEN
    RAISE EXCEPTION 'invalid arguments' USING ERRCODE = '22023';
  END IF;
  UPDATE public.outbox_events e SET status = 'DEAD', claim_token = NULL, dead_at = pg_catalog.now()
   WHERE e.id IN (SELECT x.id FROM public.outbox_events x
                   WHERE x.attempts >= x.max_attempts
                     AND (x.status = 'PENDING' OR (x.status = 'CLAIMED' AND x.claimed_until < pg_catalog.now()))
                   FOR UPDATE SKIP LOCKED);
  RETURN QUERY
  UPDATE public.outbox_events e
     SET status = 'CLAIMED', claim_token = pg_catalog.gen_random_uuid(),
         claimed_until = pg_catalog.now() + interval '2 minutes', attempts = e.attempts + 1
   WHERE e.id IN (SELECT x.id FROM public.outbox_events x
                   WHERE x.attempts < x.max_attempts
                     AND ((x.status = 'PENDING' AND x.next_attempt_at <= pg_catalog.now())
                       OR (x.status = 'CLAIMED' AND x.claimed_until < pg_catalog.now()))
                   ORDER BY x.next_attempt_at, x.id
                   FOR UPDATE SKIP LOCKED LIMIT batch_limit)
  RETURNING e.id, e.tenant_id, e.claim_token;
END
$fn$;

-- Completes a claim (current token, lease not expired) → DONE.
CREATE OR REPLACE FUNCTION outbox_api.complete_event(event_id uuid, claim_token uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  UPDATE public.outbox_events e SET status = 'DONE', claim_token = NULL, claimed_until = NULL, dispatched_at = pg_catalog.now()
   WHERE e.id = complete_event.event_id AND e.status = 'CLAIMED' AND e.claim_token = complete_event.claim_token
     AND e.claimed_until >= pg_catalog.now();
  IF NOT FOUND THEN
    RAISE EXCEPTION 'stale or invalid claim' USING ERRCODE = '55000';
  END IF;
END
$fn$;

-- Fails a claim → PENDING with backoff 1 / 5 / 15 / 60 minutes (D-B1-21 schedule reused).
CREATE OR REPLACE FUNCTION outbox_api.fail_event(event_id uuid, claim_token uuid, error_code text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  IF error_code IS NOT NULL AND error_code !~ '^[A-Z][A-Z0-9_]{0,63}$' THEN
    RAISE EXCEPTION 'invalid error code' USING ERRCODE = '22023';
  END IF;
  UPDATE public.outbox_events e
     SET status = 'PENDING', claim_token = NULL, claimed_until = NULL, last_error_code = fail_event.error_code,
         next_attempt_at = pg_catalog.now() + (ARRAY[interval '1 minute', interval '5 minutes', interval '15 minutes', interval '60 minutes'])[LEAST(e.attempts, 4)]
   WHERE e.id = fail_event.event_id AND e.status = 'CLAIMED' AND e.claim_token = fail_event.claim_token
     AND e.claimed_until >= pg_catalog.now();
  IF NOT FOUND THEN
    RAISE EXCEPTION 'stale or invalid claim' USING ERRCODE = '55000';
  END IF;
END
$fn$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA outbox_api FROM PUBLIC;

RESET ROLE;

GRANT USAGE ON SCHEMA outbox_api TO eora_relay;
GRANT EXECUTE ON FUNCTION outbox_api.claim_events(text, integer), outbox_api.complete_event(uuid, uuid),
  outbox_api.fail_event(uuid, uuid, text) TO eora_relay;
