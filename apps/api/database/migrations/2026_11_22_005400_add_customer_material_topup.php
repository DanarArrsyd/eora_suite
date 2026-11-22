<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner-aware planning (doc 39, D-141 D-3). A customer that supplies a material keeps it apart; company material makes up
 * its shortfall in planning only when the customer is marked *company may top up*. Default off: planning then says the
 * customer must supply it. A material counts as customer-supplied once the customer owns a lot of it (D-135).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE customers ADD COLUMN company_material_topup boolean NOT NULL DEFAULT false;
            GRANT UPDATE (company_material_topup) ON customers TO eora_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE UPDATE (company_material_topup) ON customers FROM eora_app;
            ALTER TABLE customers DROP COLUMN company_material_topup;
            SQL);
    }
};
