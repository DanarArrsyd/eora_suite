<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 5a (doc 29 §1.5, doc 02, ADR-0005, D-073, D-080): net weights and conversion profiles through the HTTP API and
 * the database guards behind them — inputs with units, maker ≠ checker validation, no back-dating, a successor ends its
 * predecessor, retirement, and the BOM line's profile reference. Stored and validated only (no GYV/PvS/UMV here).
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
});

function cvItem(object $test, array $t, string $code, string $type, string $uom = 'PCS'): array
{
    $u = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from uoms where code = ?', [$uom])->id);

    return spa($test)->postJson('/api/v1/core/items', ['code' => $code, 'name' => "Item {$code}", 'itemType' => $type, 'baseUomId' => $u])->assertCreated()->json('data');
}

/** @return array<string, array{value: string, unit: string}> complete COIL_V1 inputs of the doc 02 coil example */
function cvCoilInputs(): array
{
    return [
        'thickness_mm' => ['value' => '1.2', 'unit' => 'mm'], 'width_mm' => ['value' => '120', 'unit' => 'mm'],
        'density_g_cm3' => ['value' => '7.85', 'unit' => 'g/cm3'], 'pitch_mm' => ['value' => '320', 'unit' => 'mm'],
        'parts_per_stroke' => ['value' => '1', 'unit' => 'pc/stroke'], 'head_tail_mm' => ['value' => '1500', 'unit' => 'mm'],
        'setup_mm' => ['value' => '0', 'unit' => 'mm'],
    ];
}

function cvAct(object $test, string $kind, string $id, string $action, array $body = [])
{
    return spa($test)->postJson("/api/v1/planning/{$kind}/{$id}/actions/{$action}", $body);
}

it('versions net weights: counted made items only, no back-dating, maker ≠ checker, a successor ends its predecessor', function () {
    orgUser($this, $this->t, 'CSA');
    $part = cvItem($this, $this->t, 'BRK-100', 'FINISHED');
    $coil = cvItem($this, $this->t, 'COIL-1', 'RAW', 'KG');

    spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $coil['id'], 'effectiveFrom' => rtDay($this->t)])->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t, -1)])->assertStatus(422)->assertJsonValidationErrors(['effectiveFrom']);
    $v1 = spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t)])->assertCreated()->json('data');
    expect($v1['status'])->toBe('DRAFT')->and($v1['netWeightKg'])->toBeNull()->and($v1['versionNo'])->toBe(1);
    spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t)])->assertStatus(409); // one open version

    cvAct($this, 'item-weights', $v1['id'], 'submit')->assertStatus(422)->assertJsonValidationErrors(['netWeightKg']);
    patchWith($this, '/api/v1/planning/item-weights/'.$v1['id'], ['netWeightKg' => '0.2905'], $v1['version'])->assertOk()->assertJsonPath('data.netWeightKg', '0.290500');
    cvAct($this, 'item-weights', $v1['id'], 'submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    cvAct($this, 'item-weights', $v1['id'], 'validate')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION'); // the preparer
    cvAct($this, 'item-weights', $v1['id'], 'reject', ['reason' => 'own work'])->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    $checker = rtSwitch($this, $this->t, 'QCS');
    spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t)])->assertForbidden(); // QCS validates, never prepares
    cvAct($this, 'item-weights', $v1['id'], 'validate')->assertOk()->assertJsonPath('data.status', 'VALIDATED')->assertJsonPath('data.inUse', true);

    rtSwitch($this, $this->t, 'CSA');
    $v2 = spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'New drawing rev'])
        ->assertCreated()->json('data');
    expect($v2['netWeightKg'])->toBe('0.290500'); // copied from the validated version
    $v2 = patchWith($this, '/api/v1/planning/item-weights/'.$v2['id'], ['netWeightKg' => 0.288], $v2['version'])->assertOk()->json('data');
    cvAct($this, 'item-weights', $v2['id'], 'submit')->assertOk();
    rtSwitch($this, $this->t, 'QCS');
    cvAct($this, 'item-weights', $v2['id'], 'validate')->assertOk();

    $old = spa($this)->getJson('/api/v1/planning/item-weights/'.$v1['id'])->assertOk()->json('data');
    expect($old['status'])->toBe('VALIDATED')->and($old['effectiveTo'])->toBe(rtDay($this->t))->and($old['validatedByName'])->not->toBeNull();
    $audit = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        "select action, actor from audit_events where entity_type = 'item_weight' and action in ('item_weight.validated', 'item_weight.ended') order by occurred_at, id",
    ));
    expect(array_column(array_map(fn ($r) => (array) $r, $audit), 'action'))->toBe(['item_weight.validated', 'item_weight.ended', 'item_weight.validated'])
        ->and($audit[0]->actor)->toBe($checker['id']);

    // validated versions never change in place, and nobody but the function validates
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update item_weights set net_weight_kg = 1 where id = ?', [$v2['id']])))
        ->toThrow(QueryException::class);
    $day = rtDay($this->t, 5);
    $v3 = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'insert into item_weights (tenant_id, item_id, version_no, net_weight_kg, effective_from) values (?, ?, 9, 1, ?) returning id', [$this->t['tenant'], $part['id'], $day],
    ));
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update item_weights set status = 'VALIDATED', validated_by = created_by, validated_at = now() where id = ?", [$v3->id])))
        ->toThrow(QueryException::class);
})->group('integration', 'master');

it('drafts a coil profile with the material spec geometry and refuses inputs without their unit (doc 02)', function () {
    orgUser($this, $this->t, 'CSA');
    $part = cvItem($this, $this->t, 'BRK-100', 'FINISHED');
    $coil = cvItem($this, $this->t, 'COIL-SPCC', 'RAW', 'KG');
    spa($this)->putJson('/api/v1/core/items/'.$coil['id'].'/material-spec', ['grade' => 'SPCC', 'thicknessMm' => 1.2, 'widthMm' => 120, 'densityGCm3' => 7.85])->assertCreated();

    spa($this)->postJson('/api/v1/planning/conversion-profile-versions', ['itemId' => $coil['id'], 'inputItemId' => $part['id'], 'effectiveFrom' => rtDay($this->t)])
        ->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    $v = spa($this)->postJson('/api/v1/planning/conversion-profile-versions', ['itemId' => $part['id'], 'inputItemId' => $coil['id'], 'effectiveFrom' => rtDay($this->t)])
        ->assertCreated()->json('data');
    expect($v['inputForm'])->toBe('COIL')->and($v['formulaCode'])->toBe('COIL_V1')->and($v['status'])->toBe('DRAFT');
    $values = array_column($v['inputs'], 'value', 'key');
    expect($values)->toMatchArray(['thickness_mm' => '1.2', 'width_mm' => '120', 'density_g_cm3' => '7.85', 'pitch_mm' => null]);

    // "700 kg ÷ 0.32": a pitch without its unit, or in metres, is refused — never guessed
    $put = fn (array $inputs, int $version) => patchWith($this, '/api/v1/planning/conversion-profile-versions/'.$v['id'], ['inputs' => $inputs], $version);
    $put(['pitch_mm' => ['value' => '0.32']], $v['version'])->assertStatus(422)->assertJsonPath('errors', ['inputs.pitch_mm' => ['Give the value in mm.']]);
    $put(['pitch_mm' => ['value' => '0.32', 'unit' => 'm']], $v['version'])->assertStatus(422)->assertJsonValidationErrors(['inputs.pitch_mm']);
    $put(['pitch_mm' => ['value' => '0', 'unit' => 'mm']], $v['version'])->assertStatus(422)->assertJsonPath('errors', ['inputs.pitch_mm' => ['Must be more than 0.']]);
    $put(['parts_per_stroke' => ['value' => '1.5', 'unit' => 'pc/stroke']], $v['version'])->assertStatus(422)->assertJsonValidationErrors(['inputs.parts_per_stroke']);
    $put(['cavity' => ['value' => '2', 'unit' => 'pc']], $v['version'])->assertStatus(422)->assertJsonValidationErrors(['inputs.cavity']);

    $partial = array_intersect_key(cvCoilInputs(), array_flip(['thickness_mm', 'width_mm', 'density_g_cm3', 'pitch_mm']));
    $v = $put($partial, $v['version'])->assertOk()->json('data');
    cvAct($this, 'conversion-profile-versions', $v['id'], 'submit')->assertStatus(422)
        ->assertJsonValidationErrors(['inputs.parts_per_stroke', 'inputs.head_tail_mm', 'inputs.setup_mm']);
    $v = $put(cvCoilInputs(), $v['version'])->assertOk()->json('data');
    cvAct($this, 'conversion-profile-versions', $v['id'], 'submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

    rtSwitch($this, $this->t, 'QCS');
    $done = cvAct($this, 'conversion-profile-versions', $v['id'], 'validate')->assertOk()->json('data');
    expect($done['status'])->toBe('VALIDATED')->and(array_column($done['inputs'], 'value', 'key'))->toBe(array_map(fn ($e) => $e['value'], cvCoilInputs()));
    spa($this)->getJson('/api/v1/query/planning.conversion_profiles?filter[itemId]='.$part['id'])->assertOk()
        ->assertJsonPath('data.0.inputItemCode', 'COIL-SPCC')->assertJsonPath('data.0.versionInUseNo', 1);

    // the database holds the same rules: a unit-less value never lands, not even in a draft
    $day = rtDay($this->t, 3);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into conversion_profile_versions (tenant_id, profile_id, input_form, version_no, formula_code, inputs, effective_from)
         values (?, ?, 'COIL', 9, 'COIL_V1', '{\"pitch_mm\": {\"value\": 0.32}}', ?)", [$this->t['tenant'], $v['profileId'], $day],
    )))->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update conversion_profile_versions set inputs = inputs - 'pitch_mm' where id = ?", [$v['id']],
    )))->toThrow(QueryException::class);
    // nor beyond the API's bounds (6 decimals, 12 integer digits), even in a draft
    foreach (['320.1234567', '1000000000000'] as $i => $pitch) {
        expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
            "insert into conversion_profile_versions (tenant_id, profile_id, input_form, version_no, formula_code, inputs, effective_from)
             values (?, ?, 'COIL', ?, 'COIL_V1', jsonb_build_object('pitch_mm', jsonb_build_object('value', ?::numeric, 'unit', 'mm')), ?)",
            [$this->t['tenant'], $v['profileId'], 10 + $i, $pitch, $day],
        )))->toThrow(QueryException::class);
    }
})->group('integration', 'master');

it('drafts sheet profiles per part and material, copies the validated inputs, and rejects then reopens', function () {
    orgUser($this, $this->t, 'CSA');
    $part = cvItem($this, $this->t, 'PNL-200', 'SEMI_FINISHED');
    $sheet = cvItem($this, $this->t, 'SHT-SPCC', 'RAW', 'SHEET');
    $inputs = [
        'thickness_mm' => ['value' => '1.0', 'unit' => 'mm'], 'width_mm' => ['value' => '1219', 'unit' => 'mm'], 'length_mm' => ['value' => '2438', 'unit' => 'mm'],
        'density_g_cm3' => ['value' => '7.85', 'unit' => 'g/cm3'], 'blanks_per_sheet' => ['value' => 10, 'unit' => 'blank/sheet'],
        'parts_per_blank' => ['value' => 1, 'unit' => 'pc/blank'], 'setup_sheets' => ['value' => 0, 'unit' => 'sheet'],
    ];
    $v1 = spa($this)->postJson('/api/v1/planning/conversion-profile-versions', ['itemId' => $part['id'], 'inputItemId' => $sheet['id'], 'effectiveFrom' => rtDay($this->t), 'inputs' => $inputs])
        ->assertCreated()->json('data');
    expect($v1['inputForm'])->toBe('SHEET')->and($v1['formulaCode'])->toBe('SHEET_V1');
    cvAct($this, 'conversion-profile-versions', $v1['id'], 'submit')->assertOk();

    rtSwitch($this, $this->t, 'QCS');
    cvAct($this, 'conversion-profile-versions', $v1['id'], 'reject', ['reason' => 'Check the nesting'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    rtSwitch($this, $this->t, 'CSA');
    cvAct($this, 'conversion-profile-versions', $v1['id'], 'reopen')->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.rejectReason', null);
    cvAct($this, 'conversion-profile-versions', $v1['id'], 'submit')->assertOk();
    rtSwitch($this, $this->t, 'QCS');
    cvAct($this, 'conversion-profile-versions', $v1['id'], 'validate')->assertOk();

    rtSwitch($this, $this->t, 'CSA');
    $v2 = spa($this)->postJson('/api/v1/planning/conversion-profile-versions', ['itemId' => $part['id'], 'inputItemId' => $sheet['id'], 'effectiveFrom' => rtDay($this->t, 2)])
        ->assertCreated()->json('data');
    expect($v2['profileId'])->toBe($v1['profileId'])->and($v2['versionNo'])->toBe(2)
        ->and(array_column($v2['inputs'], 'value', 'key')['blanks_per_sheet'])->toBe('10'); // copied from v1
    $formulas = spa($this)->getJson('/api/v1/planning/conversion-formulas')->assertOk()->json('data');
    expect(array_column($formulas, 'formulaCode'))->toBe(['SHEET_V1', 'COIL_V1']);

    // an inactive profile takes no new version
    cvAct($this, 'conversion-profile-versions', $v2['id'], 'submit')->assertOk();
    cvAct($this, 'conversion-profile-versions', $v2['id'], 'withdraw')->assertOk();
    $profile = spa($this)->getJson('/api/v1/query/planning.conversion_profiles?filter[id]='.$v1['profileId'])->json('data.0');
    patchWith($this, '/api/v1/planning/conversion-profiles/'.$v1['profileId'], ['status' => 'INACTIVE'], $profile['version'])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
    cvAct($this, 'conversion-profile-versions', $v2['id'], 'submit')->assertStatus(422)->assertJsonValidationErrors(['profileId']);
})->group('integration', 'master');

it('retires a validated version: from tomorrow when in force, never when not yet started', function () {
    orgUser($this, $this->t, 'CSA');
    $part = cvItem($this, $this->t, 'BRK-100', 'FINISHED');
    $make = function (int $startIn) use ($part) {
        rtSwitch($this, $this->t, 'CSA');
        $v = spa($this)->postJson('/api/v1/planning/item-weights', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t, $startIn), 'netWeightKg' => 0.29])->assertCreated()->json('data');
        cvAct($this, 'item-weights', $v['id'], 'submit')->assertOk();
        rtSwitch($this, $this->t, 'QCS');

        return cvAct($this, 'item-weights', $v['id'], 'validate')->assertOk()->json('data');
    };
    $now = $make(0);
    $later = $make(10);

    $retired = cvAct($this, 'item-weights', $later['id'], 'retire')->assertOk()->json('data');
    expect($retired['status'])->toBe('RETIRED')->and($retired['effectiveTo'])->toBe(rtDay($this->t, 9))->and($retired['inUse'])->toBeFalse();
    $retired = cvAct($this, 'item-weights', $now['id'], 'retire')->assertOk()->json('data');
    expect($retired['effectiveTo'])->toBe(rtDay($this->t))->and($retired['inUse'])->toBeTrue();
    cvAct($this, 'item-weights', $now['id'], 'retire')->assertStatus(409);

    rtSwitch($this, $this->t, 'PPC');
    cvAct($this, 'item-weights', $now['id'], 'retire')->assertForbidden();
})->group('integration', 'master');

it('links a BOM line to the conversion profile of its component for the BOM item only', function () {
    orgUser($this, $this->t, 'CSA');
    $part = cvItem($this, $this->t, 'BRK-100', 'FINISHED');
    $other = cvItem($this, $this->t, 'BRK-200', 'FINISHED');
    $coil = cvItem($this, $this->t, 'COIL-1', 'RAW', 'KG');
    $coil2 = cvItem($this, $this->t, 'COIL-2', 'RAW', 'KG');
    $profile = fn (array $item, array $material) => spa($this)->postJson('/api/v1/planning/conversion-profile-versions', [
        'itemId' => $item['id'], 'inputItemId' => $material['id'], 'effectiveFrom' => rtDay($this->t),
    ])->assertCreated()->json('data.profileId');
    $mine = $profile($part, $coil);
    $foreign = $profile($other, $coil);
    $wrongMaterial = $profile($part, $coil2);

    $v = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $part['id'], 'effectiveFrom' => rtDay($this->t)])->assertCreated()->json('data');
    $put = fn (?string $p) => spa($this)->putJson('/api/v1/planning/bom-versions/'.$v['id'].'/lines', ['lines' => [
        ['lineNo' => 10, 'componentItemId' => $coil['id'], 'grossQtyPer' => '0.38', 'uomId' => $coil['baseUomId'], 'conversionProfileId' => $p],
    ]], ['If-Match' => '"'.$v['version'].'"']);
    $put($foreign)->assertStatus(422)->assertJsonValidationErrors(['lines.0.conversionProfileId']);
    $put($wrongMaterial)->assertStatus(422)->assertJsonValidationErrors(['lines.0.conversionProfileId']);
    $put($mine)->assertOk()->assertJsonPath('data.lines.0.conversionProfileId', $mine);

    // the database agrees: another item's profile or another component's profile never lands on a line
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update bom_lines set conversion_profile_id = ? where bom_version_id = ?', [$foreign, $v['id']])))
        ->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update bom_lines set conversion_profile_id = ? where bom_version_id = ?', [$wrongMaterial, $v['id']])))
        ->toThrow(QueryException::class);
})->group('integration', 'master');
