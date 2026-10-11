<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;

// Audit columns (doc 17) are stamped by the database from the signed actor; callers cannot forge them.
it('stamps created_by / updated_by / version from the signed actor and ignores supplied values', function () {
    $t = TenantFixtures::provision(1);
    $editor = Str::uuid()->toString();
    $forged = Str::uuid()->toString();

    $row = app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $editor), function (EoraPostgresConnection $db) use ($t, $forged) {
        $db->update('update plants set name = ?, created_by = ?, updated_by = ?, version = 99 where id = ?', ['Renamed', $forged, $forged, $t['plants'][0]]);

        return $db->selectOne('select created_by, updated_by, version from plants where id = ?', [$t['plants'][0]]);
    });

    expect($row->created_by)->toBe($t['actor'])
        ->and($row->updated_by)->toBe($editor)
        ->and($row->version)->toBe(2);
})->group('integration', 'isolation');

it('uses id as the primary key and keeps (tenant_id, id) unique for composite foreign keys', function (string $table) {
    $constraints = DB::connection('pgsql_owner')->select(
        "select contype, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and contype in ('p', 'u') order by contype, 2",
        [$table],
    );
    $defs = array_map(fn ($c) => $c->contype.' '.$c->def, $constraints);

    expect($defs)->toContain('p PRIMARY KEY (id)')->toContain('u UNIQUE (tenant_id, id)');
})->with(['companies', 'plants'])->group('integration', 'schema');
