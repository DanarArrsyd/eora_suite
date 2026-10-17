<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 5 (doc 29 §1.5, doc 02, ADR-0005, D-073, D-080): engineering conversion data — stored and validated only; GYV,
 * PvS and UMV are calculated in B8 (M5).
 *
 * - `item_weights`: validated net weight per piece (NW, kg/pc) of a counted item, versioned.
 * - `conversion_profiles`: one per (part, input material) pair (D-080 G1) — the identity a BOM line refers to;
 *   `conversion_profile_versions` hold the formula (`formula_code`) and its inputs, each with its unit; geometry is copied
 *   into the version and frozen once validated (D-080 G2), cavity / parts per blank live here too (D-080 G3).
 * - Both versioned records: DRAFT → SUBMITTED → VALIDATED, withdraw, reject, reopen, RETIRED (D-080 G4). Inclusive
 *   effective ranges never overlap once validated; only `lifecycle_api.validate_item_weight` /
 *   `validate_conversion_profile_version` validate (maker ≠ checker over every author, `master.conversion.approve`); a
 *   newly validated version ends the one it replaces the day before it starts. A retired version is no longer in force
 *   from tomorrow (or never was, if it had not started: `effective_to = effective_from − 1`, outside the constraint).
 * - `bom_lines.conversion_profile_id` (reserved in 001400) now refers to a profile of the line's component for the BOM's
 *   item.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Inputs of a formula (doc 02): {"key": {"value": <number>, "unit": "<unit>"}}; every key known, its unit the
            -- catalogue's, its value in range; complete unless the version is a DRAFT. Kept in step with
            -- App\Modules\Planning\Conversion\FormulaCatalog.
            CREATE OR REPLACE FUNCTION eora_conversion_inputs_ok(formula text, inputs jsonb, complete boolean) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE SET search_path = '' AS $fn$
            DECLARE
              spec jsonb;
              k text;
              entry jsonb;
              v numeric;
            BEGIN
              spec := CASE formula
                WHEN 'SHEET_V1' THEN '{"thickness_mm":["mm","pos"],"width_mm":["mm","pos"],"length_mm":["mm","pos"],"density_g_cm3":["g/cm3","pos"],
                                      "blanks_per_sheet":["blank/sheet","int1"],"parts_per_blank":["pc/blank","int1"],"setup_sheets":["sheet","int0"]}'::jsonb
                WHEN 'COIL_V1' THEN '{"thickness_mm":["mm","pos"],"width_mm":["mm","pos"],"density_g_cm3":["g/cm3","pos"],"pitch_mm":["mm","pos"],
                                     "parts_per_stroke":["pc/stroke","int1"],"head_tail_mm":["mm","zero"],"setup_mm":["mm","zero"]}'::jsonb
              END;
              IF spec IS NULL OR inputs IS NULL OR pg_catalog.jsonb_typeof(inputs) <> 'object' THEN
                RETURN false;
              END IF;
              FOR k, entry IN SELECT e.key, e.value FROM pg_catalog.jsonb_each(inputs) e LOOP
                -- nested: jsonb_object_keys() raises on a scalar, and an OR operand is not guaranteed to be skipped
                IF NOT spec ? k OR pg_catalog.jsonb_typeof(entry) <> 'object' THEN
                  RETURN false;
                END IF;
                IF pg_catalog.jsonb_typeof(entry -> 'value') IS DISTINCT FROM 'number' OR (entry ->> 'unit') IS DISTINCT FROM (spec -> k ->> 0)
                   OR (SELECT count(*) FROM pg_catalog.jsonb_object_keys(entry)) <> 2 THEN
                  RETURN false;
                END IF;
                v := (entry ->> 'value')::numeric;
                IF (spec -> k ->> 1 = 'pos' AND v <= 0) OR (spec -> k ->> 1 = 'zero' AND v < 0)
                   OR (spec -> k ->> 1 = 'int1' AND (v < 1 OR v <> pg_catalog.trunc(v)))
                   OR (spec -> k ->> 1 = 'int0' AND (v < 0 OR v <> pg_catalog.trunc(v))) THEN
                  RETURN false;
                END IF;
              END LOOP;
              IF complete THEN
                RETURN NOT EXISTS (SELECT 1 FROM pg_catalog.jsonb_object_keys(spec) s(k) WHERE NOT inputs ? s.k);
              END IF;
              RETURN true;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_conversion_inputs_ok(text, jsonb, boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION eora_conversion_inputs_ok(text, jsonb, boolean) TO eora_app, eora_lifecycle_definer;

            CREATE TABLE item_weights (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              version_no integer NOT NULL CHECK (version_no > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'VALIDATED', 'RETIRED')),
              net_weight_kg numeric(18,6) CHECK (net_weight_kg > 0),
              effective_from date NOT NULL,
              effective_to date,
              change_reason text CHECK (length(change_reason) BETWEEN 1 AND 500),
              prepared_by uuid NOT NULL,
              submitted_at timestamptz,
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              validated_by uuid,
              validated_at timestamptz,
              superseded_by_id uuid,
              retired_by uuid,
              retired_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, item_id, version_no),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, superseded_by_id) REFERENCES item_weights (tenant_id, id),
              CHECK (status = 'DRAFT' OR net_weight_kg IS NOT NULL),
              CHECK (effective_to IS NULL OR effective_to >= effective_from OR (status = 'RETIRED' AND effective_to = effective_from - 1)),
              CHECK ((status IN ('VALIDATED', 'RETIRED')) = (validated_by IS NOT NULL AND validated_at IS NOT NULL)),
              CHECK ((status = 'RETIRED') = (retired_at IS NOT NULL AND retired_by IS NOT NULL)),
              EXCLUDE USING gist (tenant_id WITH =, item_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)
                WHERE (status IN ('VALIDATED', 'RETIRED') AND (effective_to IS NULL OR effective_to >= effective_from))
            );
            CREATE INDEX item_weights_item_idx ON item_weights (tenant_id, item_id, status);

            CREATE TABLE conversion_profiles (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              item_id uuid NOT NULL,
              input_item_id uuid NOT NULL,
              input_form text NOT NULL CHECK (input_form IN ('SHEET', 'COIL')),
              status text NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, id, input_item_id),
              UNIQUE (tenant_id, id, input_form),
              UNIQUE (tenant_id, item_id, input_item_id),
              FOREIGN KEY (tenant_id, item_id) REFERENCES items (tenant_id, id),
              FOREIGN KEY (tenant_id, input_item_id) REFERENCES items (tenant_id, id),
              CHECK (item_id <> input_item_id)
            );
            CREATE INDEX conversion_profiles_input_idx ON conversion_profiles (tenant_id, input_item_id);

            CREATE TABLE conversion_profile_versions (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              profile_id uuid NOT NULL,
              input_form text NOT NULL,
              version_no integer NOT NULL CHECK (version_no > 0),
              status text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'SUBMITTED', 'REJECTED', 'VALIDATED', 'RETIRED')),
              formula_code text NOT NULL CHECK (formula_code IN ('SHEET_V1', 'COIL_V1')),
              inputs jsonb NOT NULL DEFAULT '{}'::jsonb,
              effective_from date NOT NULL,
              effective_to date,
              change_reason text CHECK (length(change_reason) BETWEEN 1 AND 500),
              prepared_by uuid NOT NULL,
              submitted_at timestamptz,
              reject_reason text CHECK (length(reject_reason) BETWEEN 3 AND 500),
              validated_by uuid,
              validated_at timestamptz,
              superseded_by_id uuid,
              retired_by uuid,
              retired_at timestamptz,
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, profile_id, version_no),
              FOREIGN KEY (tenant_id, profile_id, input_form) REFERENCES conversion_profiles (tenant_id, id, input_form),
              FOREIGN KEY (tenant_id, superseded_by_id) REFERENCES conversion_profile_versions (tenant_id, id),
              -- the formula fits the material's form and its inputs fit the formula (complete once submitted)
              CHECK (split_part(formula_code, '_', 1) = input_form),
              CHECK (eora_conversion_inputs_ok(formula_code, inputs, status <> 'DRAFT')),
              CHECK (effective_to IS NULL OR effective_to >= effective_from OR (status = 'RETIRED' AND effective_to = effective_from - 1)),
              CHECK ((status IN ('VALIDATED', 'RETIRED')) = (validated_by IS NOT NULL AND validated_at IS NOT NULL)),
              CHECK ((status = 'RETIRED') = (retired_at IS NOT NULL AND retired_by IS NOT NULL)),
              EXCLUDE USING gist (tenant_id WITH =, profile_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&)
                WHERE (status IN ('VALIDATED', 'RETIRED') AND (effective_to IS NULL OR effective_to >= effective_from))
            );
            CREATE INDEX conversion_profile_versions_profile_idx ON conversion_profile_versions (tenant_id, profile_id, status);

            CREATE TABLE item_weight_contributors (
              tenant_id uuid NOT NULL,
              item_weight_id uuid NOT NULL,
              actor uuid NOT NULL,
              first_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, item_weight_id, actor),
              FOREIGN KEY (tenant_id, item_weight_id) REFERENCES item_weights (tenant_id, id)
            );
            CREATE TABLE conversion_profile_version_contributors (
              tenant_id uuid NOT NULL,
              profile_version_id uuid NOT NULL,
              actor uuid NOT NULL,
              first_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (tenant_id, profile_version_id, actor),
              FOREIGN KEY (tenant_id, profile_version_id) REFERENCES conversion_profile_versions (tenant_id, id)
            );

            -- Lifecycle of both versioned records (one guard; the content columns are whatever is not a lifecycle column):
            -- content changes only while DRAFT; DRAFT ⇄ SUBMITTED → REJECTED → DRAFT by the app; VALIDATED only through the
            -- definer, which may also end a validated version the day before its successor starts; VALIDATED → RETIRED by
            -- the app, only shortening the range.
            CREATE OR REPLACE FUNCTION eora_guard_conversion_version() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              definer boolean := (current_user = 'eora_lifecycle_definer');
              lifecycle text[] := ARRAY['status', 'effective_from', 'effective_to', 'change_reason', 'submitted_at', 'reject_reason', 'validated_by',
                                        'validated_at', 'superseded_by_id', 'retired_by', 'retired_at', 'created_at', 'created_by', 'updated_at',
                                        'updated_by', 'version'];
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'DRAFT' OR NEW.validated_by IS NOT NULL OR NEW.validated_at IS NOT NULL OR NEW.superseded_by_id IS NOT NULL
                   OR NEW.submitted_at IS NOT NULL OR NEW.reject_reason IS NOT NULL OR NEW.effective_to IS NOT NULL OR NEW.retired_at IS NOT NULL THEN
                  RAISE EXCEPTION 'a version starts as an open-ended DRAFT' USING ERRCODE = '23514';
                END IF;
                NEW.prepared_by := (SELECT c.actor FROM ctx_api.context() c);
                IF NEW.prepared_by IS NULL THEN
                  RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.prepared_by IS DISTINCT FROM OLD.prepared_by OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.version_no IS DISTINCT FROM OLD.version_no THEN
                RAISE EXCEPTION 'version identity is fixed' USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT' AND (pg_catalog.to_jsonb(NEW) - lifecycle) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - lifecycle) THEN
                RAISE EXCEPTION 'only a DRAFT version is edited' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'RETIRED' THEN
                RAISE EXCEPTION 'a retired version never changes' USING ERRCODE = '23514';
              END IF;
              IF OLD.status = 'VALIDATED' THEN
                IF (NEW.effective_from, NEW.change_reason, NEW.submitted_at, NEW.reject_reason, NEW.validated_by, NEW.validated_at)
                     IS DISTINCT FROM (OLD.effective_from, OLD.change_reason, OLD.submitted_at, OLD.reject_reason, OLD.validated_by, OLD.validated_at)
                   OR NEW.effective_to IS NULL OR (OLD.effective_to IS NOT NULL AND NEW.effective_to > OLD.effective_to) THEN
                  RAISE EXCEPTION 'validated versions are immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'VALIDATED' AND definer AND NEW.superseded_by_id IS NOT NULL AND NEW.retired_at IS NULL THEN
                  RETURN NEW; -- ended by its successor
                END IF;
                IF NEW.status = 'RETIRED' AND NOT definer AND NEW.superseded_by_id IS NOT DISTINCT FROM OLD.superseded_by_id THEN
                  RETURN NEW;
                END IF;
                RAISE EXCEPTION 'validated versions are immutable' USING ERRCODE = '23514';
              END IF;
              IF NEW.status IN ('VALIDATED', 'RETIRED') THEN
                IF NOT definer OR OLD.status <> 'SUBMITTED' OR NEW.status <> 'VALIDATED' THEN
                  RAISE EXCEPTION 'only the validation function validates a version' USING ERRCODE = '23514';
                END IF;
                IF (NEW.effective_from, NEW.effective_to, NEW.change_reason, NEW.superseded_by_id, NEW.retired_at)
                   IS DISTINCT FROM (OLD.effective_from, OLD.effective_to, OLD.change_reason, OLD.superseded_by_id, OLD.retired_at) THEN
                  RAISE EXCEPTION 'validation changes nothing but the status' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.validated_by IS NOT NULL OR NEW.validated_at IS NOT NULL OR NEW.superseded_by_id IS NOT NULL OR NEW.effective_to IS NOT NULL
                 OR NEW.retired_at IS NOT NULL OR NEW.retired_by IS NOT NULL THEN
                RAISE EXCEPTION 'validation fields are set only by the validation function' USING ERRCODE = '23514';
              END IF;
              IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('DRAFT', 'SUBMITTED'))
                      OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('DRAFT', 'REJECTED'))
                      OR (OLD.status = 'REJECTED' AND NEW.status = 'DRAFT')) THEN
                RAISE EXCEPTION 'invalid version transition % → %', OLD.status, NEW.status USING ERRCODE = '23514';
              END IF;
              IF OLD.status <> 'DRAFT' AND (NEW.effective_from, NEW.change_reason) IS DISTINCT FROM (OLD.effective_from, OLD.change_reason) THEN
                RAISE EXCEPTION 'only a DRAFT version is edited' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_conversion_version() FROM PUBLIC;
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON item_weights FOR EACH ROW EXECUTE FUNCTION eora_guard_conversion_version();
            CREATE TRIGGER guard_lifecycle BEFORE INSERT OR UPDATE ON conversion_profile_versions FOR EACH ROW EXECUTE FUNCTION eora_guard_conversion_version();

            -- the profile's pair and form are its identity (a BOM line or a version may point at it)
            CREATE OR REPLACE FUNCTION eora_guard_conversion_profile() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF (NEW.tenant_id, NEW.item_id, NEW.input_item_id, NEW.input_form) IS DISTINCT FROM (OLD.tenant_id, OLD.item_id, OLD.input_item_id, OLD.input_form) THEN
                RAISE EXCEPTION 'conversion profile identity is fixed' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_conversion_profile() FROM PUBLIC;
            CREATE TRIGGER guard_identity BEFORE UPDATE ON conversion_profiles FOR EACH ROW EXECUTE FUNCTION eora_guard_conversion_profile();

            -- Contributors (maker ≠ checker covers every author). Rejecting is review, not authorship; retiring is not
            -- authorship either.
            CREATE OR REPLACE FUNCTION eora_note_conversion_contributor() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              ctx_tenant uuid;
              ctx_actor uuid;
            BEGIN
              IF current_user = 'eora_lifecycle_definer' THEN
                RETURN NULL;
              END IF;
              IF TG_OP = 'UPDATE' THEN
                IF NEW.status IN ('REJECTED', 'RETIRED') THEN
                  RETURN NULL;
                END IF;
              END IF;
              SELECT c.tenant, c.actor INTO ctx_tenant, ctx_actor FROM ctx_api.context() c;
              IF ctx_actor IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              IF TG_TABLE_NAME = 'item_weights' THEN
                INSERT INTO public.item_weight_contributors (tenant_id, item_weight_id, actor) VALUES (ctx_tenant, NEW.id, ctx_actor) ON CONFLICT DO NOTHING;
              ELSE
                INSERT INTO public.conversion_profile_version_contributors (tenant_id, profile_version_id, actor) VALUES (ctx_tenant, NEW.id, ctx_actor) ON CONFLICT DO NOTHING;
              END IF;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_note_conversion_contributor() FROM PUBLIC;
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE ON item_weights FOR EACH ROW EXECUTE FUNCTION eora_note_conversion_contributor();
            CREATE TRIGGER note_contributor AFTER INSERT OR UPDATE ON conversion_profile_versions FOR EACH ROW EXECUTE FUNCTION eora_note_conversion_contributor();

            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON item_weights FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON conversion_profiles FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON conversion_profile_versions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            -- BOM line → conversion profile (reserved column of 001400): a profile of this component for the BOM's item
            ALTER TABLE bom_lines ADD CONSTRAINT bom_lines_conversion_profile_fk
              FOREIGN KEY (tenant_id, conversion_profile_id, component_item_id) REFERENCES conversion_profiles (tenant_id, id, input_item_id);
            CREATE INDEX bom_lines_conversion_profile_idx ON bom_lines (tenant_id, conversion_profile_id) WHERE conversion_profile_id IS NOT NULL;
            CREATE OR REPLACE FUNCTION eora_check_bom_line_profile() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              IF NEW.conversion_profile_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM public.conversion_profiles p JOIN public.bom_versions v ON v.id = NEW.bom_version_id
                 WHERE p.id = NEW.conversion_profile_id AND p.item_id = v.item_id
              ) THEN
                RAISE EXCEPTION 'the conversion profile belongs to another item' USING ERRCODE = 'EO013';
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_check_bom_line_profile() FROM PUBLIC;
            CREATE TRIGGER check_profile BEFORE INSERT OR UPDATE OF conversion_profile_id ON bom_lines FOR EACH ROW EXECUTE FUNCTION eora_check_bom_line_profile();

            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['item_weights', 'conversion_profiles', 'conversion_profile_versions'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_isolation ON %I TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
              FOREACH t IN ARRAY ARRAY['item_weight_contributors', 'conversion_profile_version_contributors'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY', t);
                EXECUTE format('CREATE POLICY tenant_read ON %I FOR SELECT TO eora_app USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
                EXECUTE format('CREATE POLICY self_insert ON %I FOR INSERT TO eora_app WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND actor = (SELECT c.actor FROM ctx_api.context() c))', t);
                EXECUTE format('GRANT SELECT, INSERT ON %I TO eora_app', t);
              END LOOP;
            END
            $do$;
            GRANT UPDATE (status, net_weight_kg, effective_from, effective_to, change_reason, submitted_at, reject_reason, retired_by, retired_at)
              ON item_weights TO eora_app;
            GRANT UPDATE (status, inputs, effective_from, effective_to, change_reason, submitted_at, reject_reason, retired_by, retired_at)
              ON conversion_profile_versions TO eora_app;
            GRANT UPDATE (status) ON conversion_profiles TO eora_app;

            -- Lifecycle definer: exactly what the two validation functions read and write, bound to the signed tenant.
            GRANT SELECT ON item_weights, conversion_profile_versions TO eora_lifecycle_definer;
            GRANT UPDATE (status, effective_to, superseded_by_id, validated_by, validated_at) ON item_weights, conversion_profile_versions TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, id, item_id, input_item_id, status) ON conversion_profiles TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, item_weight_id, actor) ON item_weight_contributors TO eora_lifecycle_definer;
            GRANT SELECT (tenant_id, profile_version_id, actor) ON conversion_profile_version_contributors TO eora_lifecycle_definer;
            DO $do$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['item_weights', 'conversion_profiles', 'conversion_profile_versions', 'item_weight_contributors', 'conversion_profile_version_contributors'] LOOP
                EXECUTE format('CREATE POLICY lifecycle_definer ON %I TO eora_lifecycle_definer USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c))', t);
              END LOOP;
            END
            $do$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER check_profile ON bom_lines;
            DROP FUNCTION eora_check_bom_line_profile();
            DROP INDEX bom_lines_conversion_profile_idx;
            ALTER TABLE bom_lines DROP CONSTRAINT bom_lines_conversion_profile_fk;
            DROP TABLE conversion_profile_version_contributors;
            DROP TABLE item_weight_contributors;
            DROP TABLE conversion_profile_versions;
            DROP TABLE conversion_profiles;
            DROP TABLE item_weights;
            DROP FUNCTION eora_note_conversion_contributor();
            DROP FUNCTION eora_guard_conversion_profile();
            DROP FUNCTION eora_guard_conversion_version();
            DROP FUNCTION eora_conversion_inputs_ok(text, jsonb, boolean);
            SQL);
    }
};
