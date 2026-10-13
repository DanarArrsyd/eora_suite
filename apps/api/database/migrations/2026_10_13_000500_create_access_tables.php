<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 / B1 step 5 (doc 25 §3, §9 rows 4, 8, 9, §13; doc 18; D-060, D-B1-02, D-B1-16): permission catalogue, default
 * role templates, tenant roles and scoped role assignments.
 *
 * Catalogue content is frozen here (later changes = new migration). Permission code = "<doc 18 resource>.<action>",
 * action letters V view, C create, S submit, P post, A approve, R request_correction, X export. Matrix qualifiers in
 * doc 18 (e.g. "own WC", "≤ threshold", "pick") become resource-policy conditions in their slices; only the letter is
 * granted here. PSA has no tenant template (no platform → tenant path); MAD and APR have no matrix column, so their
 * templates start empty (content is tenant configuration, D-060).
 */
return new class extends Migration
{
    private const array ACTIONS = ['V' => 'view', 'C' => 'create', 'S' => 'submit', 'P' => 'post', 'A' => 'approve', 'R' => 'request_correction', 'X' => 'export'];

    /** doc 18 §2 (core vertical slice) and §5 (Phase 3.5 additions, baseline D-045): resource => template => letters. */
    private const array MATRIX = [
        'demand.po' => ['CSA' => 'V', 'PPC' => 'VCSP', 'MAT' => 'V', 'FGW' => 'V', 'DLV' => 'V', 'AUD' => 'V'],
        'planning.wo' => ['CSA' => 'V', 'PPC' => 'VCSP', 'MAT' => 'V', 'PRL' => 'V', 'OPR' => 'V', 'QCI' => 'V', 'QCS' => 'V', 'FGW' => 'V', 'DLV' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'planning.control_tower' => ['CSA' => 'V', 'PPC' => 'VX', 'MAT' => 'V', 'PRL' => 'V', 'QCI' => 'V', 'QCS' => 'V', 'FGW' => 'V', 'DLV' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'materials.issue' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'VCSP', 'PRL' => 'VP', 'INV' => 'V', 'AUD' => 'V'],
        'materials.return' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'VP', 'PRL' => 'VCS', 'OPR' => 'VC', 'INV' => 'V', 'AUD' => 'V'],
        'production.run' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'V', 'PRL' => 'VCSP', 'OPR' => 'VCS', 'QCI' => 'V', 'QCS' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'production.posting' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'V', 'PRL' => 'VCSP', 'OPR' => 'VCS', 'QCI' => 'V', 'QCS' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'production.lot_split' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'V', 'PRL' => 'VCSP', 'OPR' => 'VC', 'INV' => 'V', 'AUD' => 'V'],
        'quality.inspection' => ['CSA' => 'V', 'PPC' => 'V', 'PRL' => 'V', 'QCI' => 'VCSP', 'QCS' => 'VCSP', 'FGW' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'quality.disposition_release' => ['CSA' => 'V', 'PPC' => 'V', 'QCI' => 'P', 'QCS' => 'PA', 'FGW' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'warehouse.fg_receipt' => ['CSA' => 'V', 'PPC' => 'V', 'QCI' => 'V', 'QCS' => 'V', 'FGW' => 'VCSP', 'DLV' => 'V', 'INV' => 'V', 'AUD' => 'V'],
        'delivery.shipment' => ['CSA' => 'V', 'PPC' => 'V', 'FGW' => 'VP', 'DLV' => 'VCSP', 'INV' => 'V', 'AUD' => 'V'],
        'inventory.stock_count' => ['CSA' => 'V', 'PPC' => 'V', 'MAT' => 'VC', 'PRL' => 'VC', 'FGW' => 'VC', 'INV' => 'VCSPA', 'AUD' => 'V'],
        'inventory.adjustment' => ['CSA' => 'RA', 'MAT' => 'R', 'PRL' => 'R', 'FGW' => 'R', 'INV' => 'VCSR', 'AUD' => 'V'],
        'inventory.reversal' => ['CSA' => 'RA', 'PPC' => 'R', 'MAT' => 'R', 'PRL' => 'R', 'QCI' => 'R', 'QCS' => 'R', 'FGW' => 'R', 'DLV' => 'R', 'INV' => 'RA', 'AUD' => 'V'],
        'inventory.period_close' => ['CSA' => 'A', 'INV' => 'VP', 'AUD' => 'V'],
        'inventory.period_reopen' => ['CSA' => 'R', 'AUD' => 'V'],
        'recon.case' => ['CSA' => 'VA', 'PPC' => 'V', 'MAT' => 'V', 'PRL' => 'VC', 'QCI' => 'V', 'QCS' => 'V', 'INV' => 'VCSA', 'AUD' => 'V'],
        'core.approval' => ['CSA' => 'A', 'QCS' => 'A', 'INV' => 'A'],
        'core.users_roles' => ['TENANT_ADMIN' => 'VCP', 'CSA' => 'VCP', 'AUD' => 'V'],
        'core.audit' => ['TENANT_ADMIN' => 'V', 'CSA' => 'VX', 'INV' => 'V', 'AUD' => 'VX'],
        'exchange.export' => ['CSA' => 'X', 'PPC' => 'X', 'MAT' => 'X', 'PRL' => 'X', 'QCI' => 'X', 'QCS' => 'X', 'FGW' => 'X', 'DLV' => 'X', 'INV' => 'X', 'AUD' => 'X'],
        'exchange.import' => ['CSA' => 'PA', 'PPC' => 'CS', 'MAT' => 'CS', 'INV' => 'CS'],
        'demand.sales_order' => ['CSA' => 'V', 'PPC' => 'VCSP', 'FGW' => 'V', 'DLV' => 'V', 'AUD' => 'V'],
        'demand.reallocation' => ['CSA' => 'V', 'PPC' => 'VCSP', 'AUD' => 'V'],
        'demand.exception' => ['CSA' => 'V', 'PPC' => 'VCP', 'DLV' => 'V', 'AUD' => 'V'],
        'planning.mps' => ['CSA' => 'VA', 'PPC' => 'VCS', 'MAT' => 'V', 'PRL' => 'V', 'AUD' => 'V'],
        'planning.planned_order_release' => ['CSA' => 'V', 'PPC' => 'VCP', 'MAT' => 'V', 'PRL' => 'V', 'AUD' => 'V'],
        'core.calendar' => ['CSA' => 'VCP', 'PPC' => 'V', 'MAT' => 'V', 'PRL' => 'V', 'QCI' => 'V', 'QCS' => 'V', 'FGW' => 'V', 'DLV' => 'V', 'INV' => 'V', 'AUD' => 'V'],
    ];

    /** doc 18 §1 (D-060) + the bootstrap administrator role (D-B1-16): code => [name, default scope type or null]. */
    private const array TEMPLATES = [
        'TENANT_ADMIN' => ['Tenant Administrator', 'TENANT'],
        'CSA' => ['Company Super Admin', 'TENANT'],
        'MAD' => ['Module Admin', 'TENANT'],
        'PPC' => ['PPIC Planner', 'PLANT'],
        'MAT' => ['Materials PIC', 'PLANT'],
        'PRL' => ['Production Leader', 'PLANT'],
        'OPR' => ['Production Operator', 'PLANT'],
        'QCI' => ['Quality Inspector', 'PLANT'],
        'QCS' => ['Quality Supervisor', 'PLANT'],
        'FGW' => ['FG Warehouse', 'PLANT'],
        'DLV' => ['Delivery', 'PLANT'],
        'INV' => ['Inventory Controller', 'PLANT'],
        'APR' => ['Approver', null],
        'AUD' => ['Auditor / Viewer', 'TENANT'],
    ];

    /** D-B1-25 (proposed set): tenant administration, any approval, reversal and adjustment permissions. */
    private const array SENSITIVE_RESOURCES = ['core.users_roles', 'inventory.adjustment', 'inventory.reversal'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- global catalogue: read-only for the runtime role, written only by migrations (doc 25 §9 row 4)
            CREATE TABLE permissions (
              code text PRIMARY KEY CHECK (code ~ '^[a-z][a-z_]*\.[a-z][a-z_]*\.[a-z][a-z_]*$'),
              module text NOT NULL CHECK (module ~ '^[a-z][a-z_]*$'),
              action text NOT NULL CHECK (action IN ('view', 'create', 'submit', 'post', 'approve', 'request_correction', 'export')),
              sensitive boolean NOT NULL
            );
            CREATE TABLE role_templates (
              code text PRIMARY KEY CHECK (code ~ '^[A-Z][A-Z_]{1,31}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
              default_scope_type text CHECK (default_scope_type IN ('TENANT', 'COMPANY', 'PLANT'))
            );
            CREATE TABLE role_template_permissions (
              template_code text NOT NULL REFERENCES role_templates (code),
              permission_code text NOT NULL REFERENCES permissions (code),
              PRIMARY KEY (template_code, permission_code)
            );
            GRANT SELECT ON permissions, role_templates, role_template_permissions TO eora_app;

            -- tenant roles: copies of templates (doc 05: a tenant may copy and adjust; M1 has no editing path)
            CREATE TABLE roles (
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              id uuid NOT NULL DEFAULT uuidv7(),
              code text NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9_]{1,31}$'),
              name text NOT NULL CHECK (length(name) BETWEEN 1 AND 100),
              template_code text REFERENCES role_templates (code),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, code)
            );
            CREATE TABLE role_permissions (
              tenant_id uuid NOT NULL,
              role_id uuid NOT NULL,
              permission_code text NOT NULL REFERENCES permissions (code),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (tenant_id, role_id, permission_code),
              FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id)
            );
            CREATE INDEX role_permissions_code_idx ON role_permissions (tenant_id, permission_code);

            -- scoped assignment (D-B1-02: typed nullable scope columns + CHECK + composite FKs; B1 scopes TENANT/COMPANY/PLANT,
            -- warehouse and work center arrive with B2). Revocation keeps the row (history); no DELETE for any runtime role.
            CREATE TABLE role_assignments (
              tenant_id uuid NOT NULL,
              id uuid NOT NULL DEFAULT uuidv7(),
              membership_id uuid NOT NULL,
              role_id uuid NOT NULL,
              scope_type text NOT NULL CHECK (scope_type IN ('TENANT', 'COMPANY', 'PLANT')),
              company_id uuid,
              plant_id uuid,
              valid_from timestamptz NOT NULL DEFAULT now(),
              valid_until timestamptz,
              granted_by uuid NOT NULL,
              grant_reason text NOT NULL CHECK (length(grant_reason) BETWEEN 3 AND 500),
              revoked_at timestamptz,
              revoked_by uuid,
              revoke_reason text CHECK (length(revoke_reason) BETWEEN 3 AND 500),
              created_at timestamptz NOT NULL,
              created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL,
              updated_by uuid NOT NULL,
              version integer NOT NULL,
              PRIMARY KEY (id),
              UNIQUE (tenant_id, id),
              CHECK ((scope_type = 'TENANT' AND company_id IS NULL AND plant_id IS NULL)
                  OR (scope_type = 'COMPANY' AND company_id IS NOT NULL AND plant_id IS NULL)
                  OR (scope_type = 'PLANT' AND company_id IS NULL AND plant_id IS NOT NULL)),
              CHECK (valid_until IS NULL OR valid_until > valid_from),
              CHECK ((revoked_at IS NULL) = (revoked_by IS NULL) AND (revoked_at IS NULL) = (revoke_reason IS NULL)),
              FOREIGN KEY (tenant_id, membership_id) REFERENCES memberships (tenant_id, id),
              FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
              FOREIGN KEY (tenant_id, company_id) REFERENCES companies (tenant_id, id),
              FOREIGN KEY (tenant_id, plant_id) REFERENCES plants (tenant_id, id)
            );
            CREATE UNIQUE INDEX role_assignments_active_uq ON role_assignments
              (tenant_id, membership_id, role_id, scope_type, company_id, plant_id) NULLS NOT DISTINCT WHERE revoked_at IS NULL;
            CREATE INDEX role_assignments_membership_idx ON role_assignments (tenant_id, membership_id) WHERE revoked_at IS NULL;

            -- Database backstop for doc 25 §13 / SoD-06 (D-060: self-elevation always blocked): the signed actor never grants
            -- or revokes its own assignments; grantor/revoker are stamped from the signed actor, never from input; a row is
            -- revoked once and nothing else ever changes. Invoker trigger (reads memberships under the same RLS).
            CREATE OR REPLACE FUNCTION eora_guard_role_assignment() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            DECLARE
              a uuid := (SELECT c.actor FROM ctx_api.context() c);
              target uuid;
            BEGIN
              IF a IS NULL THEN
                RAISE EXCEPTION 'no signed actor context' USING ERRCODE = '42501';
              END IF;
              SELECT m.user_id INTO target FROM public.memberships m WHERE m.tenant_id = NEW.tenant_id AND m.id = NEW.membership_id;
              IF target = a THEN
                RAISE EXCEPTION 'SoD-06: an actor cannot change its own role assignments' USING ERRCODE = 'EO006';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.revoked_at IS NOT NULL OR NEW.revoked_by IS NOT NULL OR NEW.revoke_reason IS NOT NULL THEN
                  RAISE EXCEPTION 'a new assignment cannot be revoked' USING ERRCODE = '23514';
                END IF;
                NEW.granted_by := a;
              ELSE
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL THEN
                  RAISE EXCEPTION 'an assignment changes only by being revoked once' USING ERRCODE = '23514';
                END IF;
                NEW.revoked_at := pg_catalog.now();
                NEW.revoked_by := a;
              END IF;
              RETURN NEW;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_guard_role_assignment() FROM PUBLIC;

            CREATE TRIGGER guard_assignment BEFORE INSERT OR UPDATE ON role_assignments FOR EACH ROW EXECUTE FUNCTION eora_guard_role_assignment();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON roles FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON role_permissions FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON role_assignments FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();

            ALTER TABLE roles ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON roles TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON roles TO eora_app;
            ALTER TABLE role_permissions ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON role_permissions TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            GRANT SELECT, INSERT ON role_permissions TO eora_app;
            ALTER TABLE role_assignments ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON role_assignments TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c)) WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c));
            -- protected columns (doc 25 §13): only the revocation columns are updatable
            GRANT SELECT, INSERT (tenant_id, membership_id, role_id, scope_type, company_id, plant_id, valid_from, valid_until, granted_by, grant_reason)
              ON role_assignments TO eora_app;
            GRANT UPDATE (revoked_at, revoked_by, revoke_reason) ON role_assignments TO eora_app;
            SQL);

        $this->seedCatalogue();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE role_assignments;
            DROP FUNCTION eora_guard_role_assignment();
            DROP TABLE role_permissions;
            DROP TABLE roles;
            DROP TABLE role_template_permissions;
            DROP TABLE role_templates;
            DROP TABLE permissions;
            SQL);
    }

    private function seedCatalogue(): void
    {
        $grants = [];
        foreach (self::MATRIX as $resource => $byTemplate) {
            foreach ($byTemplate as $template => $letters) {
                foreach (str_split($letters) as $letter) {
                    $grants[$resource.'.'.self::ACTIONS[$letter]][$template] = true;
                }
            }
        }
        ksort($grants);

        foreach (array_keys($grants) as $code) {
            [$module, $resource, $action] = explode('.', $code);
            DB::insert('insert into permissions (code, module, action, sensitive) values (?, ?, ?, ?)', [
                $code, $module, $action, $action === 'approve' || in_array("{$module}.{$resource}", self::SENSITIVE_RESOURCES, true),
            ]);
        }
        foreach (self::TEMPLATES as $code => [$name, $scope]) {
            DB::insert('insert into role_templates (code, name, default_scope_type) values (?, ?, ?)', [$code, $name, $scope]);
        }
        foreach ($grants as $permission => $templates) {
            foreach (array_keys($templates) as $template) {
                DB::insert('insert into role_template_permissions (template_code, permission_code) values (?, ?)', [$template, $permission]);
            }
        }
    }
};
