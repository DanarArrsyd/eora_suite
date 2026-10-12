-- EORA identity and credential storage (doc 25 §2.1–§2.10, D-057). Run by bootstrap.sh as the bootstrap superuser,
-- connected to the target database, after 10_ctx.sql. Idempotent. Every object below is owned by the NOLOGIN
-- eora_auth_definer; no login role can read or write `auth` directly — only through the reviewed `auth_api`
-- functions, each executable by exactly one role (doc 25 §2.7, §2.8; schema guard allowlist).
-- Function bodies reference public.users / public.memberships / public.invitation_dispatch_requests /
-- public.security_events, created later by migrations together with the definer's narrow grants and policies.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET log_statement = 'none';

SET ROLE eora_auth_definer;

CREATE TABLE IF NOT EXISTS auth.identities (
  user_id uuid PRIMARY KEY,
  email_normalized text NOT NULL UNIQUE CHECK (email_normalized = pg_catalog.lower(email_normalized) AND length(email_normalized) BETWEEN 3 AND 254),
  status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'DISABLED')),
  password_hash text CHECK (password_hash LIKE '$argon2id$%' AND length(password_hash) <= 256),
  password_changed_at timestamptz,
  lockout_cleared_at timestamptz NOT NULL DEFAULT '-infinity',
  created_at timestamptz NOT NULL DEFAULT now(),
  last_invited_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS auth.login_failures (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id uuid NOT NULL,
  ip_hash text CHECK (ip_hash ~ '^[0-9a-f]{64}$'),
  occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS login_failures_user_idx ON auth.login_failures (user_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS login_failures_ip_idx ON auth.login_failures (ip_hash, occurred_at DESC);

CREATE TABLE IF NOT EXISTS auth.rate_buckets (
  bucket_key text NOT NULL,
  window_start timestamptz NOT NULL,
  count integer NOT NULL,
  PRIMARY KEY (bucket_key, window_start)
);

-- one invitation record per membership (R-47); usable secret only in SENT (doc 25 §2.10)
CREATE TABLE IF NOT EXISTS auth.invitations (
  membership_id uuid PRIMARY KEY,
  tenant_id uuid NOT NULL,
  user_id uuid NOT NULL,
  status text NOT NULL DEFAULT 'PENDING'
    CHECK (status IN ('PENDING', 'ISSUED', 'SENT', 'ACCEPTED', 'EXPIRED', 'CANCELLED', 'DELIVERY_FAILED')),
  secret_hash bytea UNIQUE,
  expires_at timestamptz,
  secret_generation integer NOT NULL DEFAULT 0,
  current_delivery_id uuid,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- one row per password-reset request; usable secret only in SENT (doc 25 §2.3, §2.10)
CREATE TABLE IF NOT EXISTS auth.reset_requests (
  id uuid PRIMARY KEY DEFAULT pg_catalog.gen_random_uuid(),
  user_id uuid NOT NULL,
  status text NOT NULL DEFAULT 'REQUESTED'
    CHECK (status IN ('REQUESTED', 'ISSUED', 'SENT', 'CONSUMED', 'EXPIRED', 'CANCELLED', 'DELIVERY_FAILED')),
  secret_hash bytea UNIQUE,
  expires_at timestamptz,
  secret_generation integer NOT NULL DEFAULT 0,
  current_delivery_id uuid,
  ip_hash text CHECK (ip_hash ~ '^[0-9a-f]{64}$'),
  requested_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS reset_requests_user_idx ON auth.reset_requests (user_id, requested_at DESC);

-- delivery queue with lease and bounded retries (doc 25 §2.9)
CREATE TABLE IF NOT EXISTS auth.delivery_queue (
  id uuid PRIMARY KEY DEFAULT pg_catalog.gen_random_uuid(),
  kind text NOT NULL CHECK (kind IN ('INVITATION', 'RESET')),
  tenant_id uuid,
  user_id uuid NOT NULL,
  membership_id uuid,
  status text NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'CLAIMED', 'SENT', 'DEAD', 'CANCELLED')),
  claim_token uuid,
  claimed_by text CHECK (length(claimed_by) <= 64),
  claim_expires_at timestamptz,
  attempts integer NOT NULL DEFAULT 0,
  max_attempts integer NOT NULL DEFAULT 5,
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  secret_generation_issued integer,
  last_error_code text CHECK (last_error_code ~ '^[A-Z][A-Z0-9_]{0,63}$'),
  dead_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (kind <> 'INVITATION' OR membership_id IS NOT NULL)
);
ALTER TABLE auth.delivery_queue ADD COLUMN IF NOT EXISTS reset_request_id uuid;
DO $do$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint WHERE conname = 'delivery_reset_has_request') THEN
    ALTER TABLE auth.delivery_queue ADD CONSTRAINT delivery_reset_has_request CHECK (kind <> 'RESET' OR reset_request_id IS NOT NULL);
  END IF;
END
$do$;
CREATE UNIQUE INDEX IF NOT EXISTS delivery_one_open_invitation ON auth.delivery_queue (membership_id)
  WHERE kind = 'INVITATION' AND status IN ('PENDING', 'CLAIMED');
CREATE INDEX IF NOT EXISTS delivery_due_idx ON auth.delivery_queue (next_attempt_at, id) WHERE status IN ('PENDING', 'CLAIMED');
CREATE INDEX IF NOT EXISTS delivery_membership_idx ON auth.delivery_queue (membership_id, created_at DESC);

REVOKE ALL ON ALL TABLES IN SCHEMA auth FROM PUBLIC;

-- ---------------------------------------------------------------------------------------------------------------------
-- Internal helpers (auth schema, no EXECUTE for anyone: callable only from the auth_api definers)
-- ---------------------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION auth.normalize_email(email text) RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path = '' AS $fn$
DECLARE e text := pg_catalog.lower(pg_catalog.btrim(email));
BEGIN
  IF e IS NULL OR pg_catalog.length(e) > 254 OR e !~ '^[^@\s]{1,64}@[^@\s]{1,253}\.[^@\s]{2,63}$' THEN
    RAISE EXCEPTION 'invalid email' USING ERRCODE = '22023';
  END IF;
  RETURN e;
END
$fn$;

-- conditional atomic counter (doc 25 §2.3 pattern): true while the window is under the cap
CREATE OR REPLACE FUNCTION auth.take_token(bucket text, window_size interval, cap integer) RETURNS boolean
LANGUAGE plpgsql SET search_path = '' AS $fn$
DECLARE taken integer;
BEGIN
  INSERT INTO auth.rate_buckets AS b (bucket_key, window_start, count)
  VALUES (bucket, pg_catalog.date_bin(window_size, pg_catalog.now(), '2000-01-01'::timestamptz), 1)
  ON CONFLICT (bucket_key, window_start) DO UPDATE SET count = b.count + 1 WHERE b.count < cap
  RETURNING b.count INTO taken;
  RETURN taken IS NOT NULL;
END
$fn$;

-- identity + profile in one transaction; random UUIDv4 (R-20); same answer for new and existing emails.
-- Concurrent first creation of one email is idempotent: the unique email decides, the loser re-reads the winner.
-- Above the creation cap it returns NULL (the caller refuses) so the capped event commits instead of rolling back.
CREATE OR REPLACE FUNCTION auth.ensure_identity(email text, source text) RETURNS uuid
LANGUAGE plpgsql SET search_path = '' AS $fn$
DECLARE
  e text := auth.normalize_email(email);
  uid uuid;
BEGIN
  UPDATE auth.identities SET last_invited_at = pg_catalog.now() WHERE email_normalized = e RETURNING user_id INTO uid;
  IF uid IS NOT NULL THEN
    RETURN uid;
  END IF;
  -- platform-wide cap on newly created identities (tunable; per-inviter limits live at the HTTP layer)
  IF NOT auth.take_token('identity_creation', interval '1 hour', 500) THEN
    INSERT INTO public.security_events (id, occurred_at, source, action, detail_code)
    VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), source, 'identity.creation_capped', 'CAP_REACHED');
    RETURN NULL;
  END IF;
  INSERT INTO auth.identities (user_id, email_normalized) VALUES (pg_catalog.gen_random_uuid(), e)
  ON CONFLICT (email_normalized) DO NOTHING
  RETURNING user_id INTO uid;
  IF uid IS NULL THEN
    -- a concurrent call created it first: return that identity (its profile exists, created atomically with it)
    UPDATE auth.identities SET last_invited_at = pg_catalog.now() WHERE email_normalized = e RETURNING user_id INTO uid;
    RETURN uid;
  END IF;
  INSERT INTO public.users (id, display_name, email_display, locale)
  VALUES (uid, pg_catalog.split_part(e, '@', 1), e, 'en');
  RETURN uid;
END
$fn$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA auth FROM PUBLIC;

-- ---------------------------------------------------------------------------------------------------------------------
-- auth_api: the reviewed surface. Each function: SECURITY DEFINER, search_path '', one grantee (doc 25 §2.8).
-- ---------------------------------------------------------------------------------------------------------------------

-- Login lookup (eora_auth_web). Locked or DISABLED identities return status only, never a hash.
CREATE OR REPLACE FUNCTION auth_api.login_lookup(email text)
RETURNS TABLE (user_id uuid, password_hash text, status text, locked_until timestamptz)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  i auth.identities%ROWTYPE;
  failures integer;
  last_failure timestamptz;
BEGIN
  IF email IS NULL OR pg_catalog.length(email) > 254 THEN
    RETURN;
  END IF;
  SELECT * INTO i FROM auth.identities WHERE email_normalized = pg_catalog.lower(pg_catalog.btrim(email));
  IF NOT FOUND THEN
    RETURN;
  END IF;
  -- D-B1-06: lockout after 10 failures within 15 minutes, counted after the last lockout clearance
  SELECT pg_catalog.count(*), pg_catalog.max(f.occurred_at) INTO failures, last_failure
    FROM auth.login_failures f
   WHERE f.user_id = i.user_id AND f.occurred_at > GREATEST(pg_catalog.now() - interval '15 minutes', i.lockout_cleared_at);
  user_id := i.user_id;
  status := i.status;
  IF i.status <> 'ACTIVE' THEN
    password_hash := NULL; locked_until := NULL;
  ELSIF failures >= 10 THEN
    password_hash := NULL; locked_until := last_failure + interval '15 minutes'; status := 'LOCKED';
  ELSE
    password_hash := i.password_hash; locked_until := NULL;
  END IF;
  RETURN NEXT;
END
$fn$;

-- Failed login (eora_auth_web): can only ADD failures; capped per IP hash (D-B1-06: 20/min per IP).
CREATE OR REPLACE FUNCTION auth_api.record_login_failure(user_id uuid, ip_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  IF ip_hash IS NOT NULL AND ip_hash !~ '^[0-9a-f]{64}$' THEN
    RAISE EXCEPTION 'invalid ip hash' USING ERRCODE = '22023';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM auth.identities i WHERE i.user_id = record_login_failure.user_id) THEN
    RETURN;
  END IF;
  IF NOT auth.take_token('login_failure:' || coalesce(ip_hash, '-'), interval '1 minute', 20) THEN
    RETURN;
  END IF;
  INSERT INTO auth.login_failures (user_id, ip_hash) VALUES (record_login_failure.user_id, record_login_failure.ip_hash);
END
$fn$;

-- Identity for a tenant admin's invitation (eora_auth_web) / for tenant provisioning (eora_provisioner).
CREATE OR REPLACE FUNCTION auth_api.invite_identity(email text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  RETURN auth.ensure_identity(email, 'auth_web');
END
$fn$;

CREATE OR REPLACE FUNCTION auth_api.provision_identity(email text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  RETURN auth.ensure_identity(email, 'auth_web');
END
$fn$;

-- Auth security events without tenant/user parameters (eora_auth_web); field allowlist; rate-capped noise.
CREATE OR REPLACE FUNCTION auth_api.record_security_event(action text, detail_code text, email_hmac text, ip_hash text, ua_hash text, correlation_id text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
BEGIN
  IF action NOT IN ('auth.failed', 'auth.locked', 'auth.login', 'auth.logout', 'auth.disabled_login', 'auth.reset_requested') THEN
    RAISE EXCEPTION 'action not allowed' USING ERRCODE = '22023';
  END IF;
  IF NOT auth.take_token('auth_events', interval '1 minute', 1200) THEN
    RETURN;
  END IF;
  INSERT INTO public.security_events (id, occurred_at, tenant_id, user_id, source, action, detail_code, email_hmac, ip_hash, user_agent_hash, correlation_id)
  VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), NULL, NULL, 'auth_web', action, detail_code, email_hmac, ip_hash, ua_hash, correlation_id);
END
$fn$;

-- Converts pending invitation dispatch requests into deliveries (eora_auth_delivery; doc 25 §2.4, R-40, R-47).
CREATE OR REPLACE FUNCTION auth_api.claim_invitation_dispatch(worker_id text, batch_limit integer)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  r record;
  m record;
  inv auth.invitations%ROWTYPE;
  created boolean;
  identity_status text;
  sends integer;
  delivery uuid;
  converted integer := 0;
BEGIN
  IF worker_id IS NULL OR pg_catalog.length(worker_id) > 64 OR batch_limit NOT BETWEEN 1 AND 100 THEN
    RAISE EXCEPTION 'invalid arguments' USING ERRCODE = '22023';
  END IF;
  FOR r IN
    SELECT d.id, d.tenant_id, d.membership_id, d.reason FROM public.invitation_dispatch_requests d
     WHERE d.status = 'PENDING' ORDER BY d.created_at, d.id LIMIT batch_limit FOR UPDATE SKIP LOCKED
  LOOP
    SELECT mm.tenant_id, mm.id, mm.user_id, mm.status, mm.invited_at INTO m
      FROM public.memberships mm WHERE mm.tenant_id = r.tenant_id AND mm.id = r.membership_id;
    SELECT i.status INTO identity_status FROM auth.identities i WHERE i.user_id = m.user_id;
    IF m.id IS NULL OR m.status <> 'INVITED' OR m.invited_at < pg_catalog.now() - interval '30 days'
       OR identity_status IS DISTINCT FROM 'ACTIVE' THEN
      UPDATE public.invitation_dispatch_requests SET status = 'REJECTED', rejected_reason = 'NOT_INVITABLE' WHERE id = r.id;
      CONTINUE;
    END IF;

    INSERT INTO auth.invitations (membership_id, tenant_id, user_id) VALUES (m.id, m.tenant_id, m.user_id)
    ON CONFLICT (membership_id) DO NOTHING;
    created := FOUND;
    SELECT * INTO inv FROM auth.invitations WHERE membership_id = m.id FOR UPDATE;

    IF r.reason = 'INITIAL' AND NOT created AND inv.status NOT IN ('ACCEPTED', 'EXPIRED', 'CANCELLED', 'DELIVERY_FAILED') THEN
      UPDATE public.invitation_dispatch_requests SET status = 'DUPLICATE' WHERE id = r.id;
      CONTINUE;
    END IF;
    IF inv.status IN ('ACCEPTED', 'CANCELLED') THEN
      UPDATE public.invitation_dispatch_requests SET status = 'REJECTED', rejected_reason = 'INVITATION_CLOSED' WHERE id = r.id;
      CONTINUE;
    END IF;
    SELECT pg_catalog.count(*) INTO sends FROM auth.delivery_queue q
     WHERE q.membership_id = m.id AND q.kind = 'INVITATION' AND q.created_at > pg_catalog.now() - interval '24 hours';
    IF sends >= 5 THEN
      UPDATE public.invitation_dispatch_requests SET status = 'REJECTED', rejected_reason = 'SEND_CAP' WHERE id = r.id;
      CONTINUE;
    END IF;

    -- at most one open delivery per membership: the replacement supersedes it in the same transaction
    UPDATE auth.delivery_queue SET status = 'CANCELLED', claim_token = NULL, updated_at = pg_catalog.now()
     WHERE membership_id = m.id AND kind = 'INVITATION' AND status IN ('PENDING', 'CLAIMED');
    INSERT INTO auth.delivery_queue (kind, tenant_id, user_id, membership_id) VALUES ('INVITATION', m.tenant_id, m.user_id, m.id)
    RETURNING id INTO delivery;
    UPDATE auth.invitations SET current_delivery_id = delivery, updated_at = pg_catalog.now() WHERE membership_id = m.id;
    UPDATE public.invitation_dispatch_requests SET status = 'CONVERTED', converted_delivery_id = delivery WHERE id = r.id;
    converted := converted + 1;
  END LOOP;
  RETURN converted;
END
$fn$;

-- Claims one due delivery with a lease; exhausted rows become DEAD first (eora_auth_delivery; doc 25 §2.9).
CREATE OR REPLACE FUNCTION auth_api.claim_delivery(worker_id text)
RETURNS TABLE (delivery_id uuid, claim_token uuid, kind text, recipient text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  d record;
BEGIN
  IF worker_id IS NULL OR pg_catalog.length(worker_id) > 64 THEN
    RAISE EXCEPTION 'invalid worker id' USING ERRCODE = '22023';
  END IF;
  FOR d IN
    UPDATE auth.delivery_queue q SET status = 'DEAD', dead_at = pg_catalog.now(), claim_token = NULL, updated_at = pg_catalog.now()
     WHERE q.id IN (SELECT x.id FROM auth.delivery_queue x
                     WHERE x.attempts >= x.max_attempts
                       AND (x.status = 'PENDING' OR (x.status = 'CLAIMED' AND x.claim_expires_at < pg_catalog.now()))
                     FOR UPDATE SKIP LOCKED)
    RETURNING q.id, q.kind, q.tenant_id, q.membership_id
  LOOP
    UPDATE auth.invitations SET status = 'DELIVERY_FAILED', updated_at = pg_catalog.now()
     WHERE membership_id = d.membership_id AND current_delivery_id = d.id AND status IN ('PENDING', 'ISSUED');
    UPDATE auth.reset_requests SET status = 'DELIVERY_FAILED', updated_at = pg_catalog.now()
     WHERE current_delivery_id = d.id AND status IN ('REQUESTED', 'ISSUED');
    INSERT INTO public.security_events (id, occurred_at, tenant_id, source, action, detail_code)
    VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), d.tenant_id, 'auth_delivery', 'delivery.dead', d.kind);
  END LOOP;

  RETURN QUERY
  UPDATE auth.delivery_queue q
     SET status = 'CLAIMED', claim_token = pg_catalog.gen_random_uuid(), claimed_by = worker_id,
         claim_expires_at = pg_catalog.now() + interval '2 minutes', attempts = q.attempts + 1, updated_at = pg_catalog.now()
   WHERE q.id = (SELECT x.id FROM auth.delivery_queue x
                  WHERE x.attempts < x.max_attempts
                    AND ((x.status = 'PENDING' AND x.next_attempt_at <= pg_catalog.now())
                      OR (x.status = 'CLAIMED' AND x.claim_expires_at < pg_catalog.now()))
                  ORDER BY x.next_attempt_at, x.id
                  FOR UPDATE SKIP LOCKED LIMIT 1)
  RETURNING q.id, q.claim_token, q.kind, (SELECT i.email_normalized FROM auth.identities i WHERE i.user_id = q.user_id);
END
$fn$;

-- Issues (rotates) the invitation secret for the current claim (eora_auth_delivery; doc 25 §2.8, §2.10).
CREATE OR REPLACE FUNCTION auth_api.issue_invitation_secret(delivery_id uuid, claim_token uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  q auth.delivery_queue%ROWTYPE;
  inv auth.invitations%ROWTYPE;
  m record;
  secret bytea;
BEGIN
  SELECT * INTO q FROM auth.delivery_queue x WHERE x.id = issue_invitation_secret.delivery_id FOR UPDATE;
  IF NOT FOUND OR q.kind <> 'INVITATION' OR q.status <> 'CLAIMED' OR q.claim_token IS DISTINCT FROM issue_invitation_secret.claim_token
     OR q.claim_expires_at < pg_catalog.now() THEN
    RAISE EXCEPTION 'stale or invalid claim' USING ERRCODE = '55000';
  END IF;
  SELECT * INTO inv FROM auth.invitations WHERE membership_id = q.membership_id FOR UPDATE;
  SELECT mm.status, mm.tenant_id, mm.user_id INTO m FROM public.memberships mm WHERE mm.id = q.membership_id;
  IF inv.membership_id IS NULL OR inv.current_delivery_id IS DISTINCT FROM q.id
     OR inv.status NOT IN ('PENDING', 'ISSUED', 'SENT', 'DELIVERY_FAILED')
     OR m.status IS DISTINCT FROM 'INVITED' OR m.tenant_id <> inv.tenant_id OR m.user_id <> inv.user_id
     OR NOT EXISTS (SELECT 1 FROM auth.identities i WHERE i.user_id = inv.user_id AND i.status = 'ACTIVE') THEN
    -- target no longer valid (doc 25 §2.9): cancel this delivery (and the invitation if it is the current one) and
    -- tell the worker there is nothing to send
    UPDATE auth.delivery_queue SET status = 'CANCELLED', claim_token = NULL, updated_at = pg_catalog.now() WHERE id = q.id;
    UPDATE auth.invitations SET status = 'CANCELLED', updated_at = pg_catalog.now()
     WHERE auth.invitations.membership_id = q.membership_id AND current_delivery_id = q.id
       AND status IN ('PENDING', 'ISSUED', 'SENT', 'DELIVERY_FAILED');
    RETURN NULL;
  END IF;
  secret := ext.gen_random_bytes(32);
  UPDATE auth.invitations
     SET secret_hash = pg_catalog.sha256(secret), expires_at = pg_catalog.now() + interval '72 hours',
         secret_generation = inv.secret_generation + 1, status = 'ISSUED', updated_at = pg_catalog.now()
   WHERE membership_id = q.membership_id;
  UPDATE auth.delivery_queue SET secret_generation_issued = inv.secret_generation + 1, updated_at = pg_catalog.now() WHERE id = q.id;
  INSERT INTO public.security_events (id, occurred_at, tenant_id, user_id, membership_id, source, action)
  VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), inv.tenant_id, inv.user_id, inv.membership_id, 'auth_delivery', 'invitation.secret_issued');
  RETURN pg_catalog.encode(secret, 'hex');
END
$fn$;

-- Acknowledges a send for the current claim only (eora_auth_delivery; doc 25 §2.9, R-42, R-47).
CREATE OR REPLACE FUNCTION auth_api.mark_delivery_result(delivery_id uuid, claim_token uuid, ok boolean, error_code text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  q auth.delivery_queue%ROWTYPE;
BEGIN
  IF error_code IS NOT NULL AND error_code !~ '^[A-Z][A-Z0-9_]{0,63}$' THEN
    RAISE EXCEPTION 'invalid error code' USING ERRCODE = '22023';
  END IF;
  SELECT * INTO q FROM auth.delivery_queue x WHERE x.id = mark_delivery_result.delivery_id FOR UPDATE;
  IF NOT FOUND OR q.status <> 'CLAIMED' OR q.claim_token IS DISTINCT FROM mark_delivery_result.claim_token
     OR q.claim_expires_at < pg_catalog.now() THEN
    RAISE EXCEPTION 'stale or invalid claim' USING ERRCODE = '55000';
  END IF;
  IF ok THEN
    -- success only for the secret issued under this claim: delivery and secret become SENT together, or neither
    IF q.kind = 'INVITATION' THEN
      UPDATE auth.invitations SET status = 'SENT', updated_at = pg_catalog.now()
       WHERE membership_id = q.membership_id AND current_delivery_id = q.id AND status = 'ISSUED'
         AND q.secret_generation_issued IS NOT NULL AND secret_generation = q.secret_generation_issued;
      IF NOT FOUND THEN
        RAISE EXCEPTION 'no secret issued under this claim' USING ERRCODE = '55000';
      END IF;
    ELSE
      UPDATE auth.reset_requests SET status = 'SENT', updated_at = pg_catalog.now()
       WHERE id = q.reset_request_id AND current_delivery_id = q.id AND status = 'ISSUED'
         AND q.secret_generation_issued IS NOT NULL AND secret_generation = q.secret_generation_issued;
      IF NOT FOUND THEN
        RAISE EXCEPTION 'no secret issued under this claim' USING ERRCODE = '55000';
      END IF;
    END IF;
    UPDATE auth.delivery_queue SET status = 'SENT', claim_token = NULL, updated_at = pg_catalog.now() WHERE id = q.id;
  ELSE
    -- backoff 1 / 5 / 15 / 60 minutes (D-B1-21); the secret stays ISSUED (unusable) and is rotated on the next claim
    UPDATE auth.delivery_queue
       SET status = 'PENDING', claim_token = NULL, last_error_code = error_code, updated_at = pg_catalog.now(),
           next_attempt_at = pg_catalog.now() + (ARRAY[interval '1 minute', interval '5 minutes', interval '15 minutes', interval '60 minutes'])[LEAST(q.attempts, 4)]
     WHERE id = q.id;
  END IF;
END
$fn$;

-- Accepts an invitation with the delivered secret (eora_auth_web; doc 25 §2.4, §2.10).
CREATE OR REPLACE FUNCTION auth_api.consume_invitation(secret text, new_hash text)
RETURNS TABLE (user_id uuid, tenant_id uuid, membership_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  inv record;
BEGIN
  IF secret IS NULL OR secret !~ '^[0-9a-f]{64}$' OR new_hash IS NULL OR new_hash NOT LIKE '$argon2id$%' OR pg_catalog.length(new_hash) > 256 THEN
    RETURN;
  END IF;
  SELECT i.user_id, i.tenant_id, i.membership_id INTO inv FROM auth.invitations i
   WHERE i.secret_hash = pg_catalog.sha256(pg_catalog.decode(secret, 'hex')) AND i.status = 'SENT' AND i.expires_at > pg_catalog.now()
   FOR UPDATE;
  IF NOT FOUND THEN
    RETURN; -- uniform failure: unknown, unsent, expired or replayed
  END IF;
  -- re-validate at use (doc 25 §2.8): the membership must still be INVITED and the identity ACTIVE; a revoked or
  -- suspended membership closes the invitation for good and returns the same uniform failure
  IF NOT EXISTS (SELECT 1 FROM public.memberships m
                  WHERE m.tenant_id = inv.tenant_id AND m.id = inv.membership_id AND m.user_id = inv.user_id AND m.status = 'INVITED')
     OR NOT EXISTS (SELECT 1 FROM auth.identities i WHERE i.user_id = inv.user_id AND i.status = 'ACTIVE') THEN
    UPDATE auth.invitations SET status = 'CANCELLED', updated_at = pg_catalog.now() WHERE auth.invitations.membership_id = inv.membership_id;
    RETURN;
  END IF;
  UPDATE auth.invitations SET status = 'ACCEPTED', updated_at = pg_catalog.now() WHERE auth.invitations.membership_id = inv.membership_id;
  -- the first password is set only through an accepted invitation (doc 25 §2.4); an existing password is kept
  UPDATE auth.identities SET password_hash = new_hash, password_changed_at = pg_catalog.now()
   WHERE auth.identities.user_id = inv.user_id AND password_hash IS NULL AND status = 'ACTIVE';
  IF FOUND THEN
    UPDATE public.users SET credential_version = credential_version + 1 WHERE id = inv.user_id;
  END IF;
  INSERT INTO public.security_events (id, occurred_at, tenant_id, user_id, membership_id, source, action)
  VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), inv.tenant_id, inv.user_id, inv.membership_id, 'auth_web', 'invitation.accepted');
  user_id := inv.user_id; tenant_id := inv.tenant_id; membership_id := inv.membership_id;
  RETURN NEXT;
END
$fn$;

-- Password reset request (eora_auth_web; doc 25 §2.3, R-46, R-48). Returns nothing in every case; the HTTP layer adds
-- a uniform minimum response time. Unknown / DISABLED / passwordless identities produce no row of any kind.
CREATE OR REPLACE FUNCTION auth_api.request_password_reset(email text, ip_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  i auth.identities%ROWTYPE;
  recent integer;
  rid uuid;
  did uuid;
BEGIN
  IF email IS NULL OR pg_catalog.length(email) > 254 OR (ip_hash IS NOT NULL AND ip_hash !~ '^[0-9a-f]{64}$') THEN
    RETURN;
  END IF;
  SELECT * INTO i FROM auth.identities x WHERE x.email_normalized = pg_catalog.lower(pg_catalog.btrim(email));
  IF NOT FOUND OR i.status <> 'ACTIVE' OR i.password_hash IS NULL THEN
    RETURN;
  END IF;
  -- one transaction, serialized per identity (R-48)
  PERFORM 1 FROM auth.identities x WHERE x.user_id = i.user_id FOR UPDATE;
  SELECT pg_catalog.count(*) INTO recent FROM auth.reset_requests rr
   WHERE rr.user_id = i.user_id AND rr.requested_at > pg_catalog.now() - interval '1 hour';
  IF recent >= 3 OR NOT auth.take_token('reset_delivery', interval '1 minute', 60) THEN
    INSERT INTO public.security_events (id, occurred_at, source, action, detail_code, ip_hash)
    VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), 'auth_web', 'auth.reset_throttled', CASE WHEN recent >= 3 THEN 'IDENTITY_CAP' ELSE 'GLOBAL_CAP' END, ip_hash);
    RETURN;
  END IF;
  -- a new request supersedes older open ones (and their deliveries)
  UPDATE auth.delivery_queue SET status = 'CANCELLED', claim_token = NULL, updated_at = pg_catalog.now()
   WHERE kind = 'RESET' AND user_id = i.user_id AND status IN ('PENDING', 'CLAIMED');
  UPDATE auth.reset_requests SET status = 'CANCELLED', updated_at = pg_catalog.now()
   WHERE user_id = i.user_id AND status IN ('REQUESTED', 'ISSUED', 'SENT');
  INSERT INTO auth.reset_requests (user_id, ip_hash) VALUES (i.user_id, ip_hash) RETURNING id INTO rid;
  INSERT INTO auth.delivery_queue (kind, user_id, reset_request_id) VALUES ('RESET', i.user_id, rid) RETURNING id INTO did;
  UPDATE auth.reset_requests SET current_delivery_id = did WHERE id = rid;
  INSERT INTO public.security_events (id, occurred_at, user_id, source, action, ip_hash)
  VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), i.user_id, 'auth_web', 'auth.reset_requested', ip_hash);
END
$fn$;

-- Issues (rotates) the reset secret for the current claim (eora_auth_delivery; doc 25 §2.8, §2.10). NULL = the
-- request is no longer valid; the delivery and request are cancelled and there is nothing to send.
CREATE OR REPLACE FUNCTION auth_api.issue_reset_secret(delivery_id uuid, claim_token uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  q auth.delivery_queue%ROWTYPE;
  rr auth.reset_requests%ROWTYPE;
  secret bytea;
BEGIN
  SELECT * INTO q FROM auth.delivery_queue x WHERE x.id = issue_reset_secret.delivery_id FOR UPDATE;
  IF NOT FOUND OR q.kind <> 'RESET' OR q.status <> 'CLAIMED' OR q.claim_token IS DISTINCT FROM issue_reset_secret.claim_token
     OR q.claim_expires_at < pg_catalog.now() THEN
    RAISE EXCEPTION 'stale or invalid claim' USING ERRCODE = '55000';
  END IF;
  SELECT * INTO rr FROM auth.reset_requests x WHERE x.id = q.reset_request_id FOR UPDATE;
  IF rr.id IS NULL OR rr.current_delivery_id IS DISTINCT FROM q.id OR rr.status NOT IN ('REQUESTED', 'ISSUED')
     OR rr.requested_at < pg_catalog.now() - interval '2 hours'
     OR NOT EXISTS (SELECT 1 FROM auth.identities i WHERE i.user_id = rr.user_id AND i.status = 'ACTIVE' AND i.password_hash IS NOT NULL) THEN
    UPDATE auth.delivery_queue SET status = 'CANCELLED', claim_token = NULL, updated_at = pg_catalog.now() WHERE id = q.id;
    UPDATE auth.reset_requests SET status = 'CANCELLED', updated_at = pg_catalog.now()
     WHERE id = q.reset_request_id AND status IN ('REQUESTED', 'ISSUED');
    RETURN NULL;
  END IF;
  secret := ext.gen_random_bytes(32);
  UPDATE auth.reset_requests
     SET secret_hash = pg_catalog.sha256(secret), expires_at = pg_catalog.now() + interval '30 minutes',
         secret_generation = rr.secret_generation + 1, status = 'ISSUED', updated_at = pg_catalog.now()
   WHERE id = rr.id;
  UPDATE auth.delivery_queue SET secret_generation_issued = rr.secret_generation + 1, updated_at = pg_catalog.now() WHERE id = q.id;
  INSERT INTO public.security_events (id, occurred_at, user_id, source, action)
  VALUES (pg_catalog.gen_random_uuid(), pg_catalog.now(), rr.user_id, 'auth_delivery', 'auth.reset_secret_issued');
  RETURN pg_catalog.encode(secret, 'hex');
END
$fn$;

-- Consumes a reset secret (eora_auth_web; doc 25 §2.3 step 3): single use, sets the new password, clears the lockout
-- (email ownership proven), cancels every other open request, bumps credential_version (all sessions die, R-39) and
-- writes one auth event per tenant where the user holds an ACTIVE membership.
CREATE OR REPLACE FUNCTION auth_api.consume_password_reset(secret text, new_hash text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  rr auth.reset_requests%ROWTYPE;
BEGIN
  IF secret IS NULL OR secret !~ '^[0-9a-f]{64}$' OR new_hash IS NULL OR new_hash NOT LIKE '$argon2id$%' OR pg_catalog.length(new_hash) > 256 THEN
    RETURN false;
  END IF;
  SELECT * INTO rr FROM auth.reset_requests x
   WHERE x.secret_hash = pg_catalog.sha256(pg_catalog.decode(secret, 'hex')) AND x.status = 'SENT' AND x.expires_at > pg_catalog.now()
   FOR UPDATE;
  IF NOT FOUND THEN
    RETURN false; -- uniform failure: unknown, unsent, expired or replayed
  END IF;
  IF NOT EXISTS (SELECT 1 FROM auth.identities i WHERE i.user_id = rr.user_id AND i.status = 'ACTIVE' AND i.password_hash IS NOT NULL) THEN
    UPDATE auth.reset_requests SET status = 'CANCELLED', updated_at = pg_catalog.now() WHERE id = rr.id;
    RETURN false;
  END IF;
  UPDATE auth.reset_requests SET status = 'CONSUMED', updated_at = pg_catalog.now() WHERE id = rr.id;
  UPDATE auth.reset_requests SET status = 'CANCELLED', updated_at = pg_catalog.now()
   WHERE user_id = rr.user_id AND id <> rr.id AND status IN ('REQUESTED', 'ISSUED', 'SENT');
  UPDATE auth.delivery_queue SET status = 'CANCELLED', claim_token = NULL, updated_at = pg_catalog.now()
   WHERE kind = 'RESET' AND user_id = rr.user_id AND status IN ('PENDING', 'CLAIMED');
  UPDATE auth.identities
     SET password_hash = new_hash, password_changed_at = pg_catalog.now(), lockout_cleared_at = pg_catalog.now()
   WHERE user_id = rr.user_id;
  UPDATE public.users SET credential_version = credential_version + 1 WHERE id = rr.user_id;
  INSERT INTO public.security_events (id, occurred_at, tenant_id, user_id, membership_id, source, action)
  SELECT pg_catalog.gen_random_uuid(), pg_catalog.now(), m.tenant_id, rr.user_id, m.id, 'auth_web', 'auth.password_reset_consumed'
    FROM public.memberships m WHERE m.user_id = rr.user_id AND m.status = 'ACTIVE';
  RETURN true;
END
$fn$;

-- Removes orphan identities (eora_auth_delivery scheduler; doc 25 §2.4, R-25): older than 7 days, never set a password,
-- no membership, no open delivery, no unexpired sent invitation. Each candidate is re-checked under its row lock; the
-- users DELETE policy re-checks "no membership" again and memberships.user_id is ON DELETE RESTRICT.
CREATE OR REPLACE FUNCTION auth_api.cleanup_orphan_identities(batch_limit integer)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $fn$
DECLARE
  c record;
  removed integer := 0;
BEGIN
  IF batch_limit NOT BETWEEN 1 AND 500 THEN
    RAISE EXCEPTION 'invalid batch limit' USING ERRCODE = '22023';
  END IF;
  FOR c IN
    SELECT i.user_id FROM auth.identities i
     WHERE i.created_at < pg_catalog.now() - interval '7 days' AND i.last_invited_at < pg_catalog.now() - interval '7 days'
       AND i.password_hash IS NULL
     ORDER BY i.created_at LIMIT batch_limit FOR UPDATE SKIP LOCKED
  LOOP
    IF EXISTS (SELECT 1 FROM public.memberships m WHERE m.user_id = c.user_id)
       OR EXISTS (SELECT 1 FROM auth.delivery_queue q WHERE q.user_id = c.user_id AND q.status IN ('PENDING', 'CLAIMED'))
       OR EXISTS (SELECT 1 FROM auth.invitations v WHERE v.user_id = c.user_id AND v.status = 'SENT' AND v.expires_at > pg_catalog.now()) THEN
      CONTINUE;
    END IF;
    BEGIN
      DELETE FROM auth.invitations WHERE user_id = c.user_id;
      DELETE FROM auth.reset_requests WHERE user_id = c.user_id;
      DELETE FROM auth.delivery_queue WHERE user_id = c.user_id;
      DELETE FROM auth.login_failures WHERE user_id = c.user_id;
      DELETE FROM auth.identities WHERE user_id = c.user_id;
      DELETE FROM public.users WHERE id = c.user_id;
      IF NOT FOUND THEN
        RAISE EXCEPTION 'profile not removable' USING ERRCODE = '55000'; -- a membership appeared: undo this candidate
      END IF;
      removed := removed + 1;
    EXCEPTION WHEN OTHERS THEN
      CONTINUE; -- e.g. a membership committed first (ON DELETE RESTRICT): skip, the invite flow wins
    END;
  END LOOP;
  RETURN removed;
END
$fn$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA auth_api FROM PUBLIC;

RESET ROLE;

-- exactly one grantee per function (doc 25 §2.1, §2.8)
GRANT USAGE ON SCHEMA auth_api TO eora_auth_web, eora_auth_delivery, eora_provisioner;
GRANT EXECUTE ON FUNCTION auth_api.login_lookup(text), auth_api.record_login_failure(uuid, text), auth_api.invite_identity(text),
  auth_api.record_security_event(text, text, text, text, text, text), auth_api.consume_invitation(text, text),
  auth_api.request_password_reset(text, text), auth_api.consume_password_reset(text, text) TO eora_auth_web;
GRANT EXECUTE ON FUNCTION auth_api.claim_invitation_dispatch(text, integer), auth_api.claim_delivery(text),
  auth_api.issue_invitation_secret(uuid, uuid), auth_api.mark_delivery_result(uuid, uuid, boolean, text),
  auth_api.issue_reset_secret(uuid, uuid), auth_api.cleanup_orphan_identities(integer) TO eora_auth_delivery;
GRANT EXECUTE ON FUNCTION auth_api.provision_identity(text) TO eora_provisioner;
