<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\ItemWeightsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Master Data → Conversion Data → Net weights (doc 29 §1.5, ADR-0005 `NW`, D-073, D-080): the validated net weight in kg
 * of one piece of a counted item, versioned with an effective date of today or later. Prepared with
 * `master.conversion.create`/`submit`, validated by a second person with `master.conversion.approve` through
 * `lifecycle_api.validate_item_weight`. Stored only; UMV is calculated in B8. Edits use If-Match; every write is audited.
 */
final class ItemWeightController
{
    use ConversionLifecycle;
    use MasterWrites;
    use VersionLifecycle;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /**
     * A new DRAFT net weight version for a piece-counted finished or Semi-Finished Material item; without `netWeightKg`
     * the latest validated weight is copied. An item has at most one open version at a time.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId' => ['required', 'uuid'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'netWeightKg' => ['nullable', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,6'],
            'changeReason' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $item = $db->selectOne(
            'select i.id, i.item_type, i.status, u.uom_class from items i join uoms u on u.id = i.base_uom_id where i.id = ? for update of i',
            [strtolower($data['itemId'])],
        ) ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($item->status !== 'ACTIVE' || ! in_array($item->item_type, BomController::MADE_ITEM_TYPES, true) || $item->uom_class !== 'COUNT') {
            throw ValidationException::withMessages(['itemId' => 'Choose an active finished or Semi-Finished Material item counted in pieces.']);
        }
        if ($data['effectiveFrom'] < $this->today($db)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
        }
        self::refuseOpenVersion($db, 'item_weights', 'item_id', $item->id);
        $weight = isset($data['netWeightKg']) ? (string) $data['netWeightKg']
            : $db->selectOne("select net_weight_kg::text as w from item_weights where item_id = ? and status in ('VALIDATED', 'RETIRED') order by version_no desc limit 1", [$item->id])?->w;
        $no = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from item_weights where item_id = ?', [$item->id])->n;
        $id = (string) $db->selectOne(
            'insert into item_weights (tenant_id, item_id, version_no, net_weight_kg, effective_from, change_reason)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$item->id, $no, $weight, $data['effectiveFrom'], self::optionalText($data['changeReason'] ?? null)],
        )->id;
        $this->audit->record($db, 'item_weight.created', 'item_weight', $id, null, $this->state($db, 'item_weights', $id), ['versionNo' => $no]);

        return $this->respondWeight($db, $id, 201);
    }

    /** One net weight version. */
    public function show(string $weight): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'master.conversion.view');

        return $this->respondWeight($db, $this->existing($db, 'item_weights', $weight));
    }

    /** Edit a DRAFT version's weight, start day or reason (If-Match). */
    public function updateWeight(Request $request, string $weight): JsonResponse
    {
        $data = $request->validate([
            'netWeightKg' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,6'],
            'effectiveFrom' => ['sometimes', 'date_format:Y-m-d'],
            'changeReason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $row] = $this->lockedVersion('item_weights', $weight, self::CREATE, 'DRAFT');

        $changes = [];
        if (array_key_exists('netWeightKg', $data)) {
            $changes['net_weight_kg'] = $data['netWeightKg'] === null ? null : (string) $data['netWeightKg'];
        }
        if (isset($data['effectiveFrom'])) {
            if ($data['effectiveFrom'] < $this->today($db)) {
                throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
            }
            $changes['effective_from'] = $data['effectiveFrom'];
        }
        if (array_key_exists('changeReason', $data)) {
            $changes['change_reason'] = self::optionalText($data['changeReason']);
        }
        $this->update($db, 'item_weights', 'item_weight', (string) $row->id, $expected, $changes, 'item_weight.updated');

        return $this->respondWeight($db, (string) $row->id);
    }

    /** DRAFT → SUBMITTED: a weight, a start day not in the past. */
    public function submit(string $weight): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('item_weights', $weight, self::SUBMIT, 'DRAFT');
        $problems = [];
        if ($row->net_weight_kg === null) {
            $problems['netWeightKg'] = 'Enter the net weight before submitting.';
        }
        if ($row->effective_from < $this->today($db)) {
            $problems['effectiveFrom'] = 'The version would take effect in the past. Choose today or a later day.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
        $this->moveTo($db, 'item_weights', 'item_weight', $row, 'SUBMITTED', ['submitted_at' => now()->toIso8601String()], 'item_weight.submitted', null);

        return $this->respondWeight($db, (string) $row->id);
    }

    /** SUBMITTED → DRAFT to change it. */
    public function withdraw(string $weight): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('item_weights', $weight, self::SUBMIT, 'SUBMITTED');
        $this->moveTo($db, 'item_weights', 'item_weight', $row, 'DRAFT', ['submitted_at' => null], 'item_weight.withdrawn', null);

        return $this->respondWeight($db, (string) $row->id);
    }

    /** SUBMITTED → REJECTED with a reason, by a validator who did not prepare or edit it. */
    public function reject(Request $request, string $weight): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $row] = $this->lockedVersion('item_weights', $weight, self::APPROVE, 'SUBMITTED');
        $this->refuseAuthor($db, 'item_weight_contributors', 'item_weight_id', $row, 'A net weight is reviewed by someone who did not prepare or edit it.');
        $this->moveTo($db, 'item_weights', 'item_weight', $row, 'REJECTED', ['reject_reason' => $data['reason']], 'item_weight.rejected', null);

        return $this->respondWeight($db, (string) $row->id);
    }

    /** REJECTED → DRAFT to rework it. */
    public function reopen(string $weight): JsonResponse
    {
        [$db, $row] = $this->lockedVersion('item_weights', $weight, self::SUBMIT, 'REJECTED');
        $this->moveTo($db, 'item_weights', 'item_weight', $row, 'DRAFT', ['reject_reason' => null, 'submitted_at' => null], 'item_weight.reopened', null);

        return $this->respondWeight($db, (string) $row->id);
    }

    /** SUBMITTED → VALIDATED by a second person; the validated version it overlaps ends the day before. */
    public function validate(string $weight): JsonResponse
    {
        $id = $this->validateThrough('item_weights', 'validate_item_weight', $weight);

        return $this->respondWeight($this->db(), $id);
    }

    /** VALIDATED → RETIRED: not in force after today (never, if it had not started). */
    public function retire(string $weight): JsonResponse
    {
        $id = $this->retireVersion('item_weights', 'item_weight', $weight);

        return $this->respondWeight($this->db(), $id);
    }

    private function respondWeight(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        return $this->respond($db, new ItemWeightsQuery, $id, $status);
    }
}
