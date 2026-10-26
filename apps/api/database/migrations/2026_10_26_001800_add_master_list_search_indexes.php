<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 step 7a (doc 29 §1.7, acceptance §4 item 5, D-093 amends D-B2-09): under forced RLS only leakproof operators can
 * drive an index, and LIKE/ILIKE are not leakproof — so the trigram indexes of D-B2-09 were never used and only cost
 * writes: they go. Code prefixes use `^@` (starts_with, leakproof) on `text_pattern_ops` B-trees; name searches filter
 * the tenant's rows. Added: name sort for items, routing code prefix, routings by item (joined item searches find the
 * items first, then the rows by item id). EXPLAIN-checked in ListPlanTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX items_search_trgm_idx;
            DROP INDEX customers_search_trgm_idx;
            DROP INDEX suppliers_search_trgm_idx;
            DROP INDEX customer_items_part_trgm_idx;
            CREATE INDEX items_name_idx ON items (tenant_id, name);
            CREATE INDEX routings_code_prefix_idx ON routings (tenant_id, routing_code text_pattern_ops);
            CREATE INDEX routings_item_idx ON routings (tenant_id, item_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX routings_item_idx;
            DROP INDEX routings_code_prefix_idx;
            DROP INDEX items_name_idx;
            CREATE INDEX customer_items_part_trgm_idx ON customer_items USING gin (customer_part_no ext.gin_trgm_ops);
            CREATE INDEX suppliers_search_trgm_idx ON suppliers USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);
            CREATE INDEX customers_search_trgm_idx ON customers USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);
            CREATE INDEX items_search_trgm_idx ON items USING gin (code ext.gin_trgm_ops, name ext.gin_trgm_ops);
            SQL);
    }
};
