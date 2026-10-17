<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Conversion\FormulaCatalog;
use App\Modules\Planning\Queries\ConversionProfilesQuery;
use App\Modules\Planning\Queries\ConversionProfileVersionsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → Conversion Data → Conversion profiles (doc 29 §1.5, doc 02, ADR-0005 `CP`, D-080). A profile belongs to
 * one (part, input material) pair; its versions hold the formula (`formula_code`) and the inputs, each with its unit —
 * geometry copied from the material spec, cavity / parts per blank included. Prepared with `master.conversion.create`/
 * `submit`, validated by a second person with `master.conversion.approve` through
 * `lifecycle_api.validate_conversion_profile_version`. Stored and validated only; theoretical pieces are calculated in
 * B8. Edits use If-Match; every write is audited.
 */
final class ConversionProfileController
{
    use ConversionLifecycle;
    use MasterWrites;
    use VersionLifecycle;

    /** Base unit class of the input material → form (coil in kg, sheets counted). */
    private const array FORM_BY_UOM_CLASS = ['MASS' => 'COIL', 'COUNT' => 'SHEET'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** The formula catalogue: inputs, units and rules per `formula_code`. */
    public function formulas(): JsonResponse
    {
        $this->authorizer->requireAnywhere($this->db(), 'master.conversion.view');
        $data = [];
        foreach (FormulaCatalog::FORMULAS as $code => $formula) {
            $inputs = [];
            foreach ($formula['inputs'] as $key => $input) {
                $inputs[] = ['key' => $key, 'label' => $input['label'], 'unit' => $input['unit'], 'rule' => $input['rule']];
            }
            $data[] = ['formulaCode' => $code, 'inputForm' => $formula['form'], 'label' => $formula['label'], 'inputs' => $inputs];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * A new DRAFT version of the profile of (`itemId`, `inputItemId`) — the profile is created on its first version. The
     * form follows the material's base unit (kg → COIL, counted → SHEET). Without `inputs` the latest validated version's
     * inputs are copied, or for a first version the thickness, width and density of the material spec. A profile has at
     * most one open version at a time.
     */
    public function storeVersion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId' => ['required', 'uuid'],
            'inputItemId' => ['required', 'uuid'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'changeReason' => ['nullable', 'string', 'max:500'],
            'inputs' => ['nullable', 'array'],
            'inputs.*' => ['array'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $item = $this->countedPart($db, strtolower($data['itemId']));
        $material = $db->selectOne(
            'select i.id, i.item_type, i.status, u.uom_class from items i join uoms u on u.id = i.base_uom_id where i.id = ?',
            [strtolower($data['inputItemId'])],
        );
        $form = self::FORM_BY_UOM_CLASS[$material->uom_class ?? ''] ?? null;
        if ($material === null || $material->status !== 'ACTIVE' || ! in_array($material->item_type, ['RAW', 'SEMI_FINISHED'], true) || $form === null) {
            throw ValidationException::withMessages(['inputItemId' => 'Choose an active raw or Semi-Finished material kept in kg (coil) or counted (sheets).']);
        }
        if ($material->id === $item->id) {
            throw ValidationException::withMessages(['inputItemId' => 'A part is not its own material.']);
        }
        if ($data['effectiveFrom'] < $this->today($db)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
        }
        $formula = FormulaCatalog::forForm($form);

        $profile = $db->selectOne('select id, status from conversion_profiles where item_id = ? and input_item_id = ? for update', [$item->id, $material->id]);
        if ($profile === null) {
            $profileId = (string) $db->selectOne(
                'insert into conversion_profiles (tenant_id, item_id, input_item_id, input_form) values ((select c.tenant from ctx_api.context() c), ?, ?, ?) returning id',
                [$item->id, $material->id, $form],
            )->id;
            $this->audit->record($db, 'conversion_profile.created', 'conversion_profile', $profileId, null, $this->state($db, 'conversion_profiles', $profileId), []);
        } elseif ($profile->status !== 'ACTIVE') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Profile inactive', 'Activate the conversion profile before drafting a version.');
        } else {
            $profileId = (string) $profile->id;
            self::refuseOpenVersion($db, 'conversion_profile_versions', 'profile_id', $profileId);
        }

        if (isset($data['inputs'])) {
            $inputs = self::checkedInputs($formula, $data['inputs'], false);
        } else {
            $previous = $db->selectOne(
                "select id from conversion_profile_versions where profile_id = ? and status in ('VALIDATED', 'RETIRED') order by version_no desc limit 1",
                [$profileId],
            );
            $inputs = $previous !== null ? $this->storedInputs($db, (string) $previous->id) : $this->specInputs($db, $formula, (string) $material->id);
        }
        $no = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from conversion_profile_versions where profile_id = ?', [$profileId])->n;
        $id = (string) $db->selectOne(
            'insert into conversion_profile_versions (tenant_id, profile_id, input_form, version_no, formula_code, inputs, effective_from, change_reason)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?::jsonb, ?, ?) returning id',
            [$profileId, $form, $no, $formula, FormulaCatalog::toJson($inputs), $data['effectiveFrom'], self::optionalText($data['changeReason'] ?? null)],
        )->id;
        $this->audit->record($db, 'conversion_profile_version.created', 'conversion_profile_version', $id, null, $this->state($db, 'conversion_profile_versions', $id), ['versionNo' => $no]);

        return $this->respondVersion($db, $id, 201);
    }

    /** One version with its inputs (in the formula's order, each with its unit). */
    public function showVersion(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'master.conversion.view');

        return $this->respondVersion($db, $this->existing($db, 'conversion_profile_versions', $version));
    }

    /** Edit a DRAFT version (If-Match): start day, reason, and `inputs` replaced as a whole (may be incomplete). */
    public function updateVersion(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'effectiveFrom' => ['sometimes', 'date_format:Y-m-d'],
            'changeReason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'inputs' => ['sometimes', 'array'],
            'inputs.*' => ['array'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $row] = $this->lockedVersion('conversion_profile_versions', $version, self::CREATE, 'DRAFT');

        $changes = [];
        if (isset($data['effectiveFrom'])) {
            if ($data['effectiveFrom'] < $this->today($db)) {
                throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
            }
            $changes['effective_from'] = $data['effectiveFrom'];
        }
        if (array_key_exists('changeReason', $data)) {
            $changes['change_reason'] = self::optionalText($data['changeReason']);
        }
        if (array_key_exists('inputs', $data)) {
            $changes['inputs'] = FormulaCatalog::toJson(self::checkedInputs((string) $row->formula_code, $data['inputs'], false));
        }
        $this->update($db, 'conversion_profile_versions', 'conversion_profile_version', (string) $row->id, $expected, $changes, 'conversion_profile_version.updated');

        return $this->respondVersion($db, (string) $row->id);
    }

    /** DRAFT → SUBMITTED: every input of the formula, an active profile, a start day not in the past. */
    public function submit(string $version): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('conversion_profile_versions', $version, self::SUBMIT, 'DRAFT');
        $problems = [];
        foreach (FormulaCatalog::problems((string) $row->formula_code, $this->storedInputs($db, (string) $row->id), true) as $key => $message) {
            $problems["inputs.{$key}"] = $message;
        }
        if ($row->effective_from < $this->today($db)) {
            $problems['effectiveFrom'] = 'The version would take effect in the past. Choose today or a later day.';
        }
        if ($db->selectOne("select 1 as ok from conversion_profiles where id = ? and status = 'ACTIVE'", [$row->profile_id]) === null) {
            $problems['profileId'] = 'The conversion profile is inactive.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
        $this->moveTo($db, 'conversion_profile_versions', 'conversion_profile_version', $row, 'SUBMITTED', ['submitted_at' => now()->toIso8601String()], 'conversion_profile_version.submitted', null);

        return $this->respondVersion($db, (string) $row->id);
    }

    /** SUBMITTED → DRAFT to change it. */
    public function withdraw(string $version): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('conversion_profile_versions', $version, self::SUBMIT, 'SUBMITTED');
        $this->moveTo($db, 'conversion_profile_versions', 'conversion_profile_version', $row, 'DRAFT', ['submitted_at' => null], 'conversion_profile_version.withdrawn', null);

        return $this->respondVersion($db, (string) $row->id);
    }

    /** SUBMITTED → REJECTED with a reason, by a validator who did not prepare or edit it. */
    public function reject(Request $request, string $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $row] = $this->lockedVersion('conversion_profile_versions', $version, self::APPROVE, 'SUBMITTED');
        $this->refuseAuthor($db, 'conversion_profile_version_contributors', 'profile_version_id', $row, 'A conversion profile is reviewed by someone who did not prepare or edit it.');
        $this->moveTo($db, 'conversion_profile_versions', 'conversion_profile_version', $row, 'REJECTED', ['reject_reason' => $data['reason']], 'conversion_profile_version.rejected', null);

        return $this->respondVersion($db, (string) $row->id);
    }

    /** REJECTED → DRAFT to rework it. */
    public function reopen(string $version): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('conversion_profile_versions', $version, self::SUBMIT, 'REJECTED');
        $this->moveTo($db, 'conversion_profile_versions', 'conversion_profile_version', $row, 'DRAFT', ['reject_reason' => null, 'submitted_at' => null], 'conversion_profile_version.reopened', null);

        return $this->respondVersion($db, (string) $row->id);
    }

    /** SUBMITTED → VALIDATED by a second person; the validated version it overlaps ends the day before. */
    public function validate(string $version): JsonResponse
    {
        $id = $this->validateThrough('conversion_profile_versions', 'validate_conversion_profile_version', $version);

        return $this->respondVersion($this->db(), $id);
    }

    /** VALIDATED → RETIRED: not in force after today (never, if it had not started). */
    public function retire(string $version): JsonResponse
    {
        $id = $this->retireVersion('conversion_profile_versions', 'conversion_profile_version', $version);

        return $this->respondVersion($this->db(), $id);
    }

    /** Activate or deactivate a profile (If-Match). An inactive profile gets no new version and no BOM line. */
    public function updateProfile(Request $request, string $profile): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,INACTIVE']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'conversion_profiles', $profile);
        $this->update($db, 'conversion_profiles', 'conversion_profile', $id, $expected, ['status' => $data['status']], 'conversion_profile.updated');

        return $this->respond($db, new ConversionProfilesQuery, $id);
    }

    private function countedPart(EoraPostgresConnection $db, string $id): stdClass
    {
        $item = $db->selectOne(
            'select i.id, i.item_type, i.status, u.uom_class from items i join uoms u on u.id = i.base_uom_id where i.id = ? for update of i',
            [$id],
        );
        if ($item === null || $item->status !== 'ACTIVE' || ! in_array($item->item_type, BomController::MADE_ITEM_TYPES, true) || $item->uom_class !== 'COUNT') {
            throw ValidationException::withMessages(['itemId' => 'Choose an active finished or Semi-Finished Material item counted in pieces.']);
        }

        return $item;
    }

    /**
     * @param  array<array-key, mixed>  $inputs
     * @return array<string, array{value: int|float|string, unit: string}>
     */
    private static function checkedInputs(string $formula, array $inputs, bool $complete): array
    {
        $problems = FormulaCatalog::problems($formula, $inputs, $complete);
        if ($problems !== []) {
            throw ValidationException::withMessages(array_combine(array_map(static fn (string $k) => "inputs.{$k}", array_keys($problems)), $problems));
        }

        /** @var array<string, array{value: int|float|string, unit: string}> $inputs */
        return array_map(static fn (array $e) => ['value' => $e['value'], 'unit' => $e['unit']], $inputs);
    }

    /** @return array<string, array{value: string, unit: string}> */
    private function storedInputs(EoraPostgresConnection $db, string $id): array
    {
        $inputs = [];
        foreach ($db->select("select e.key, e.value ->> 'value' as value, e.value ->> 'unit' as unit from conversion_profile_versions v, jsonb_each(v.inputs) e where v.id = ?", [$id]) as $row) {
            $inputs[(string) $row->key] = ['value' => (string) $row->value, 'unit' => (string) $row->unit];
        }

        return $inputs;
    }

    /** @return array<string, array{value: string, unit: string}> thickness, width and density from the material spec */
    private function specInputs(EoraPostgresConnection $db, string $formula, string $material): array
    {
        $spec = $db->selectOne('select thickness_mm::text as thickness_mm, width_mm::text as width_mm, density_g_cm3::text as density_g_cm3 from material_specs where item_id = ?', [$material]);
        $inputs = [];
        foreach (FormulaCatalog::SPEC_INPUTS as $key => $column) {
            $value = $spec?->{$column};
            if ($value !== null) {
                $inputs[$key] = ['value' => self::plain((string) $value), 'unit' => FormulaCatalog::FORMULAS[$formula]['inputs'][$key]['unit']];
            }
        }

        return $inputs;
    }

    /** "1.200" → "1.2", "120.000" → "120" (a JSON number, exact). */
    private static function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** The version (list representation) with its formula inputs; ETag = version row version. */
    private function respondVersion(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new ConversionProfileVersionsQuery, $id) ?? throw new \LogicException('Written row not visible.');
        $stored = $this->storedInputs($db, $id);
        $row['inputs'] = array_map(
            static fn (string $key, array $field) => [
                'key' => $key, 'label' => $field['label'], 'unit' => $field['unit'], 'value' => $stored[$key]['value'] ?? null,
            ],
            array_keys(FormulaCatalog::FORMULAS[$row['formulaCode']]['inputs']),
            FormulaCatalog::FORMULAS[$row['formulaCode']]['inputs'],
        );

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
