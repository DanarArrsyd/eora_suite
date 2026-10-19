<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 6b (doc 29 §1.6): the remaining valid-rows templates — organisation (warehouses, locations, work centers,
 * machines, dies, die parts), partners (customers, suppliers, customer part mapping), unit conversions and process
 * types. Each applies the screen's rules: codes, references by code, the user's plant scope, versions from today.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    Queue::fake();
    Storage::fake('imports');
});

/** Imports `$rows` through the whole flow and returns the job row. */
function mtImport(object $test, array $t, string $template, array $header, array $rows): stdClass
{
    return impJob($t, impThrough($test, $t, impFile($header, $rows, "{$template}.xlsx"), $template));
}

/** @return list<string> errors of the job's error rows, in row order */
function mtErrors(object $test, string $job): array
{
    return array_merge(...array_column(spa($test)->getJson("/api/v1/exchange/imports/{$job}/rows?status=ERROR")->json('data'), 'errors'));
}

it('imports the plant layout in order: warehouses, locations, work centers with their MAP location, machines', function () {
    orgUser($this, $this->t, 'CSA');
    $wh = mtImport($this, $this->t, 'warehouses', ['Plant code', 'Code', 'Name', 'Warehouse type'], [
        ['P1', 'RM', 'Raw material', 'RAW_MATERIAL'], ['P1', 'FG', 'Finished goods', 'finished_goods'], ['P9', 'X', 'Nowhere', 'GENERAL'], ['P1', 'RM', 'Again', 'GENERAL'],
    ]);
    expect($wh)->committed_rows->toBe(2)->error_rows->toBe(2);
    expect(mtErrors($this, $wh->id))->toBe(['Plant P9 does not exist.', 'Warehouse RM is also on row 2 of this file.']);

    $loc = mtImport($this, $this->t, 'locations', ['Plant code', 'Warehouse code', 'Code', 'Name', 'Location type'], [
        ['P1', 'RM', 'RM-A1', 'Rack A1', 'RM_WAREHOUSE'], ['P1', 'RM', 'MAP-X', 'Reserved', 'RM_WAREHOUSE'], ['P1', 'ZZ', 'RM-A2', 'Rack A2', 'RM_WAREHOUSE'],
    ]);
    expect($loc)->committed_rows->toBe(1);
    expect(mtErrors($this, $loc->id))->toBe(['Codes starting with V_ or MAP- are reserved for virtual and Material at Production locations.', 'Warehouse ZZ does not exist in this plant.']);

    $wc = mtImport($this, $this->t, 'work_centers', ['Plant code', 'Code', 'Name', 'Work center type', 'Available hours per shift'], [
        ['P1', 'PRS-01', 'Press line', 'PRESS', '7.5'], ['P1', 'WLD-01', 'Weld', 'WELD', '25'],
    ]);
    expect($wc)->committed_rows->toBe(1);
    expect(mtErrors($this, $wc->id))->toBe(['Available hours per shift: above 0, at most 24, with at most 2 decimals.']);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select count(*) as n from locations where code = 'MAP-PRS-01'")->n))->toBe(1);

    $m = mtImport($this, $this->t, 'machines', ['Plant code', 'Work center code', 'Code', 'Name', 'Stroke counter'], [
        ['P1', 'PRS-01', 'M-200', 'Press 200 t', 'YES'], ['P1', 'WLD-01', 'W-1', 'Welder', ''],
    ]);
    expect($m)->committed_rows->toBe(1);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select stroke_counter from machines where code = 'M-200'")->stroke_counter))->toBeTrue();
})->group('integration', 'import');

it('keeps org imports inside the user\'s plant scope', function () {
    [$p1, $p2] = $this->t['plants'];
    orgUser($this, $this->t, 'CSA', ['PLANT', $p1]);
    $wh = mtImport($this, $this->t, 'warehouses', ['Plant code', 'Code', 'Name', 'Warehouse type'], [['P1', 'RM', 'Raw', 'RAW_MATERIAL'], ['P2', 'RM', 'Raw', 'RAW_MATERIAL']]);
    expect($wh)->committed_rows->toBe(1);
    expect(mtErrors($this, $wh->id))->toBe(['Plant P2 is outside your roles.']);
    expect($p2)->toBeString();
})->group('integration', 'import');

it('imports dies (codes unique in the tenant) and the parts each die makes', function () {
    orgUser($this, $this->t, 'CSA');
    impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(2)));
    $dies = mtImport($this, $this->t, 'dies', ['Plant code', 'Code', 'Name', 'Die type', 'Cavities', 'Stroke life', 'Current strokes'], [
        ['P1', 'D-100', 'Bracket die', 'DIE', '2', '500000', ''], ['P2', 'D-100', 'Same code', 'DIE', '', '', ''], ['P2', 'J-1', 'Jig', 'JIG', '0', '', ''],
    ]);
    expect($dies)->committed_rows->toBe(1);
    expect(mtErrors($this, $dies->id))->toBe(['Die D-100 is also on row 2 of this file.', 'Cavities: enter a whole number from 1 to 1000.']);

    $links = mtImport($this, $this->t, 'die_items', ['Die code', 'Item code', 'Parts per stroke'], [['D-100', 'PART-0001', '2'], ['D-100', 'PART-0009', ''], ['D-999', 'PART-0002', '']]);
    expect($links)->committed_rows->toBe(1);
    expect(mtErrors($this, $links->id))->toBe(['Item PART-0009 does not exist.', 'Die D-999 does not exist.']);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select parts_per_stroke from die_items')->parts_per_stroke))->toBe(2);
})->group('integration', 'import');

it('imports customers, suppliers and customer part mapping with the screen\'s version rule', function () {
    orgUser($this, $this->t, 'CSA');
    impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(2)));
    $c = mtImport($this, $this->t, 'customers', ['Code', 'Name'], [['ASTRA', 'Astra'], ['astra', 'Twice'], ['BAD CODE', 'x']]);
    expect($c)->committed_rows->toBe(1)->error_rows->toBe(2);
    expect(mtImport($this, $this->t, 'suppliers', ['Code', 'Name'], [['KS', 'Krakatau Steel']]))->committed_rows->toBe(1);

    $today = rtDay($this->t);
    $later = rtDay($this->t, 10);
    $first = mtImport($this, $this->t, 'customer_items', ['Customer code', 'Customer part no', 'Item code', 'Customer drawing rev', 'Effective from'], [
        ['ASTRA', 'AS-77', 'PART-0001', '0', $today],
    ]);
    expect($first)->committed_rows->toBe(1);
    // a later version of the same customer part closes the open one the day before; an earlier one is refused
    $second = mtImport($this, $this->t, 'customer_items', ['Customer code', 'Customer part no', 'Item code', 'Customer drawing rev', 'Effective from'], [
        ['ASTRA', 'AS-77', 'PART-0002', 'B', $later], ['ASTRA', 'AS-88', 'PART-0001', '', '2026-02-30'],
    ]);
    expect($second)->committed_rows->toBe(1);
    expect(mtErrors($this, $second->id))->toBe(['Effective from: enter a date as YYYY-MM-DD.']);
    $rows = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select('select customer_drawing_rev, effective_from::text as f, effective_to::text as t from customer_items order by effective_from'));
    expect($rows[0]->t)->toBe(rtDay($this->t, 9))->and($rows[0]->customer_drawing_rev)->toBe('0')->and($rows[1]->t)->toBeNull();
})->group('integration', 'import');

it('imports unit conversions from today, closing the open version, and process types with the routing permission', function () {
    orgUser($this, $this->t, 'CSA');
    impThrough($this, $this->t, impFile(IMP_ITEM_HEADER, impItems(1)));
    $conv = mtImport($this, $this->t, 'uom_conversions', ['Item code', 'From unit', 'To unit', 'Factor', 'Effective from'], [
        ['PART-0001', 'SHEET', 'KG', '12.5', rtDay($this->t)], ['PART-0001', 'KG', 'KG', '1', rtDay($this->t)], ['PART-0001', 'SHEET', 'PCS', '10', '2020-01-01'],
    ]);
    expect($conv)->committed_rows->toBe(1);
    expect(mtErrors($this, $conv->id))->toContain('From unit and To unit must differ.');
    $next = mtImport($this, $this->t, 'uom_conversions', ['Item code', 'From unit', 'To unit', 'Factor', 'Effective from'], [['PART-0001', 'KG', 'SHEET', '0.08', rtDay($this->t, 3)]]);
    expect($next)->committed_rows->toBe(1);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select effective_to::text as t from uom_conversions where factor = 12.5')->t))->toBe(rtDay($this->t, 2));

    $pt = mtImport($this, $this->t, 'process_types', ['Code', 'Name', 'Category', 'Usual work center type'], [['BLANK', 'Blanking', 'TRANSFORMATION', 'PRESS'], ['INSP', 'Final inspection', 'CHECK', '']]);
    expect($pt)->committed_rows->toBe(1);
    expect(mtErrors($this, $pt->id))->toBe(['Category: use one of TRANSFORMATION, INSPECTION, HANDLING.']);
})->group('integration', 'import');

it('lists every template with its permission and columns', function () {
    orgUser($this, $this->t, 'CSA');
    $codes = array_column(spa($this)->getJson('/api/v1/exchange/import-templates')->assertOk()->json('data'), 'permission', 'code');
    expect($codes)->toMatchArray([
        'uoms' => 'master.part.create', 'items' => 'master.part.create', 'material_specs' => 'master.part.create', 'uom_conversions' => 'master.part.create',
        'customers' => 'master.part.create', 'customer_items' => 'master.part.create', 'suppliers' => 'master.part.create',
        'warehouses' => 'master.org.create', 'locations' => 'master.org.create', 'work_centers' => 'master.org.create', 'machines' => 'master.org.create',
        'dies' => 'master.org.create', 'die_items' => 'master.org.create', 'process_types' => 'master.routing.create',
    ]);
})->group('integration', 'import');
