<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * D-126: the Supervisor role template (`SUP`) holds every permission of the catalogue, also those later migrations add
 * (a trigger on `permissions` gives each new one to it), so the owner's account opens every menu and may enter anything.
 * Separation of duties is unchanged: the database still refuses that someone approves or releases what they prepared.
 * Existing tenants get the role through `eora:tenants:sync`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO role_templates (code, name, default_scope_type) VALUES ('SUP', 'Supervisor', 'TENANT') ON CONFLICT (code) DO NOTHING;
            INSERT INTO role_template_permissions (template_code, permission_code)
              SELECT 'SUP', p.code FROM permissions p ON CONFLICT DO NOTHING;

            -- every permission added later goes to the Supervisor template too
            CREATE OR REPLACE FUNCTION eora_supervisor_gets_permission() RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $fn$
            BEGIN
              INSERT INTO public.role_template_permissions (template_code, permission_code) VALUES ('SUP', NEW.code) ON CONFLICT DO NOTHING;
              RETURN NULL;
            END
            $fn$;
            REVOKE ALL ON FUNCTION eora_supervisor_gets_permission() FROM PUBLIC;
            CREATE TRIGGER supervisor_gets_permission AFTER INSERT ON permissions FOR EACH ROW EXECUTE FUNCTION eora_supervisor_gets_permission();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supervisor_gets_permission ON permissions;
            DROP FUNCTION IF EXISTS eora_supervisor_gets_permission();
            SQL);
        // tenant roles made from the template stay (their members keep access); only an unused template is removed
        try {
            DB::transaction(function (): void {
                DB::delete("delete from role_template_permissions where template_code = 'SUP'");
                DB::delete("delete from role_templates where code = 'SUP'");
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23503') {
                throw $e;
            }
        }
    }
};
