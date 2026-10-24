<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 6d (doc 29 §1.6, D-081): the UAT sample files import cleanly, file after file, into a new tenant with plant
 * P1 — every row committed, routings and BOMs as Draft, the maps once their versions are submitted.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('imports');
});

it('writes the sample files in import order and every row of every file commits', function () {
    orgUser($this, $this->t, 'CSA');
    $dir = sys_get_temp_dir().'/eora-samples-'.bin2hex(random_bytes(4));
    expect(Artisan::call('eora:demo:import-samples', ['dir' => $dir, '--from' => rtDay($this->t)]))->toBe(0);
    $files = glob("{$dir}/*.xlsx") ?: [];
    expect(array_map('basename', $files))->toHaveCount(17)->and(basename($files[0]))->toBe('01-uoms.xlsx')->and(basename($files[16]))->toBe('17-bom-routing-maps.xlsx');

    foreach ($files as $path) {
        $template = str_replace('-', '_', (string) preg_replace('/^\d+-|\.xlsx$/', '', basename($path)));
        if ($template === 'bom_routing_maps') {
            // a map is drawn on submitted versions (D-079)
            foreach (AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select('select id from routing_versions')) as $rv) {
                spa($this)->postJson("/api/v1/planning/routing-versions/{$rv->id}/actions/submit")->assertOk();
            }
            foreach (AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select('select id from bom_versions')) as $bv) {
                spa($this)->postJson("/api/v1/planning/bom-versions/{$bv->id}/actions/submit")->assertOk();
            }
        }
        $id = impUpload($this, new UploadedFile($path, basename($path), null, null, true), $template)->assertCreated()->json('data.id');
        impWork($this->t, $id, 'validate');
        expect(array_merge(...array_column(spa($this)->getJson("/api/v1/exchange/imports/{$id}/rows?status=ERROR")->json('data'), 'errors')))->toBe([]);
        spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/submit")->assertOk();
        spa($this)->postJson("/api/v1/exchange/imports/{$id}/actions/commit")->assertOk();
        impWork($this->t, $id, 'commit');
        $job = impJob($this->t, $id);
        expect($job->status)->toBe('COMMITTED')->and($job->committed_rows)->toBe($job->total_rows);
    }

    $counts = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select (select count(*) from items) as items, (select count(*) from routing_operations) as ops, (select count(*) from bom_lines) as lines,
                (select count(*) from bom_routing_maps where status = 'DRAFT') as maps, (select count(*) from customers) as customers",
    ));
    expect($counts)->items->toBe(8)->ops->toBe(7)->lines->toBe(4)->maps->toBe(3)->customers->toBe(4);
})->group('integration', 'import');

it('refuses a sample folder date that is not a date', function () {
    expect(Artisan::call('eora:demo:import-samples', ['dir' => sys_get_temp_dir().'/eora-samples-x', '--from' => '2026-02-30']))->toBe(2);
})->group('import');
