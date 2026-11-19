<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saved list views (EORA-COMPONENT-STANDARDS §4, UI/UX audit U-13): a user's own named search, filters, sort and
 * visible columns for one list. Personal only — RLS limits every row to the signed-in user in the active tenant.
 * No business data: the state is screen settings, so a view never grants access to anything (the list re-checks scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE list_views (
              tenant_id uuid NOT NULL REFERENCES tenants(id),
              id uuid PRIMARY KEY DEFAULT uuidv7(),
              user_id uuid NOT NULL,
              list_key text NOT NULL CHECK (list_key ~ '^[a-z0-9][a-z0-9.-]{0,79}$'),
              name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 60 AND name = btrim(name)),
              state jsonb NOT NULL CHECK (jsonb_typeof(state) = 'object' AND pg_column_size(state) <= 8192),
              created_at timestamptz NOT NULL, created_by uuid NOT NULL,
              updated_at timestamptz NOT NULL, updated_by uuid NOT NULL, version integer NOT NULL,
              UNIQUE (tenant_id, id),
              UNIQUE (tenant_id, user_id, list_key, name)
            );
            CREATE INDEX list_views_user_idx ON list_views (tenant_id, user_id, list_key);
            ALTER TABLE list_views ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY;
            CREATE POLICY own_views ON list_views TO eora_app
              USING (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND user_id = (SELECT c.actor FROM ctx_api.context() c))
              WITH CHECK (tenant_id = (SELECT c.tenant FROM ctx_api.context() c) AND user_id = (SELECT c.actor FROM ctx_api.context() c));
            GRANT SELECT, INSERT, DELETE ON list_views TO eora_app;
            GRANT UPDATE (name, state) ON list_views TO eora_app;
            CREATE TRIGGER stamp_audit BEFORE INSERT OR UPDATE ON list_views FOR EACH ROW EXECUTE FUNCTION eora_stamp_audit();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE list_views');
    }
};
