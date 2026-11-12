<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M3 step 9 (doc 32 §1.9, D-093): the Movement Ledger pages newest first and filters by item. The ledger's id is a
 * UUIDv7, so id order is posting order; both indexes lead with the tenant (the RLS predicate) and are declared in the
 * list's own order (`id DESC NULLS LAST`), so a page is read from the index without sorting the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX inventory_movements_ledger_idx ON inventory_movements (tenant_id, id DESC NULLS LAST);
            CREATE INDEX inventory_movements_item_idx ON inventory_movements (tenant_id, item_id, id DESC NULLS LAST);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS inventory_movements_item_idx;
            DROP INDEX IF EXISTS inventory_movements_ledger_idx;
            SQL);
    }
};
