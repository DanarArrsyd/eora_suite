<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\BomVersionsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → BOMs (doc 29 §1.4, doc 26 §2.5, D-B2-01, O-026, D-078). A BOM version belongs to an item for every plant
 * and is maintained with `master.routing.*` in any scope (D-078). Lines are edited as one list while DRAFT; a version is
 * submitted, then approved by someone who did not prepare or edit it through `lifecycle_api.approve_bom_version`, which
 * also refuses a component chain that comes back to the item. Edits use If-Match; every write is audited.
 */
final class BomController
{
    use MasterWrites;
    use VersionLifecycle;

    private const string CREATE = 'master.routing.create';

    private const string SUBMIT = 'master.routing.submit';

    private const string APPROVE = 'master.routing.approve';

    /** Items that are made, so they have a bill of material. */
    public const array MADE_ITEM_TYPES = ['FINISHED', 'SEMI_FINISHED'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /**
     * A new DRAFT BOM version for an item, copying the lines of `copyFromVersionId` (default: its latest approved
     * version). An item has at most one open version (DRAFT, SUBMITTED or REJECTED) at a time.
     */
    public function storeVersion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId' => ['required', 'uuid'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'changeReason' => ['nullable', 'string', 'max:500'],
            'copyFromVersionId' => ['nullable', 'uuid'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $item = $db->selectOne('select id, item_type, status from items where id = ? for update', [strtolower($data['itemId'])])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($item->status !== 'ACTIVE' || ! in_array($item->item_type, self::MADE_ITEM_TYPES, true)) {
            throw ValidationException::withMessages(['itemId' => 'Choose an active finished or Semi-Finished Material item; bought items have no BOM.']);
        }
        if ($data['effectiveFrom'] < $this->today($db)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
        }
        if ($db->selectOne("select 1 as ok from bom_versions where item_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$item->id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Open version exists', 'Finish or withdraw the open BOM version of this item first.');
        }
        $source = isset($data['copyFromVersionId'])
            ? $db->selectOne('select id from bom_versions where id = ? and item_id = ?', [strtolower($data['copyFromVersionId']), $item->id])
            : $db->selectOne("select id from bom_versions where item_id = ? and status in ('APPROVED', 'SUPERSEDED') order by version_no desc limit 1", [$item->id]);
        if (isset($data['copyFromVersionId']) && $source === null) {
            throw ValidationException::withMessages(['copyFromVersionId' => 'Unknown BOM version of this item.']);
        }
        $no = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from bom_versions where item_id = ?', [$item->id])->n;
        $id = (string) $db->selectOne(
            'insert into bom_versions (tenant_id, item_id, version_no, effective_from, change_reason) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$item->id, $no, $data['effectiveFrom'], self::optionalText($data['changeReason'] ?? null)],
        )->id;
        if ($source !== null) {
            $db->insert(
                'insert into bom_lines (tenant_id, bom_version_id, line_no, component_item_id, gross_qty_per, uom_id, scrap_allowance, conversion_profile_id, line_purpose)
                 select tenant_id, ?, line_no, component_item_id, gross_qty_per, uom_id, scrap_allowance, conversion_profile_id, line_purpose
                   from bom_lines where bom_version_id = ? order by line_no',
                [$id, $source->id],
            );
        }
        $this->audit->record($db, 'bom_version.created', 'bom_version', $id, null, $this->state($db, 'bom_versions', $id), ['versionNo' => $no, 'copiedFrom' => $source?->id]);

        return $this->respondVersion($db, $id, 201);
    }

    /** One BOM version with its lines. */
    public function showVersion(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'master.routing.view');

        return $this->respondVersion($db, $this->existing($db, 'bom_versions', $version));
    }

    /** Edit a DRAFT version's start day or reason (If-Match). */
    public function updateVersion(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'effectiveFrom' => ['sometimes', 'date_format:Y-m-d'],
            'changeReason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $ver] = $this->locked($version, self::CREATE, 'DRAFT');

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
        $this->update($db, 'bom_versions', 'bom_version', (string) $ver->id, $expected, $changes, 'bom_version.updated');

        return $this->respondVersion($db, (string) $ver->id);
    }

    /**
     * Replace the whole line list of a DRAFT version (If-Match on the version). Quantities are per finished piece in the
     * line's material unit (O-026): the component's base unit or a unit it has a conversion to or from. A component on
     * several lines is allowed when each of those lines says what it is for (D-B2-01, B2-AT-10).
     */
    public function putLines(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.lineNo' => ['required', 'integer', 'between:1,9999', 'distinct'],
            'lines.*.componentItemId' => ['required', 'uuid'],
            'lines.*.grossQtyPer' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'lines.*.uomId' => ['required', 'uuid'],
            'lines.*.scrapAllowance' => ['nullable', 'numeric', 'min:0', 'lt:1', 'decimal:0,5'],
            'lines.*.linePurpose' => ['nullable', 'string', 'max:200'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $ver] = $this->locked($version, self::CREATE, 'DRAFT');
        $id = (string) $ver->id;

        $components = array_count_values(array_map(static fn (array $l) => strtolower($l['componentItemId']), $data['lines']));
        foreach ($data['lines'] as $i => $line) {
            $this->checkLine($db, $ver, $line, $i, $components[strtolower($line['componentItemId'])] > 1);
        }
        // the version row carries the optimistic lock for its whole content
        if ($db->update('update bom_versions set change_reason = change_reason where id = ? and version = ?', [$id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->delete('delete from bom_lines where bom_version_id = ?', [$id]);
        foreach ($data['lines'] as $line) {
            $db->insert(
                'insert into bom_lines (tenant_id, bom_version_id, line_no, component_item_id, gross_qty_per, uom_id, scrap_allowance, line_purpose)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?)',
                [$id, $line['lineNo'], strtolower($line['componentItemId']), (string) $line['grossQtyPer'], strtolower($line['uomId']),
                    (string) ($line['scrapAllowance'] ?? 0), self::optionalText($line['linePurpose'] ?? null)],
            );
        }
        $this->audit->record($db, 'bom_version.lines_replaced', 'bom_version', $id, null, null, ['lineCount' => count($data['lines'])]);

        return $this->respondVersion($db, $id);
    }

    /** DRAFT → SUBMITTED: a line at least, a start day not in the past. */
    public function submit(string $version): JsonResponse
    {
        [$db, $ver] = $this->locked($version, self::SUBMIT, 'DRAFT');
        $problems = [];
        if ($db->selectOne('select 1 as ok from bom_lines where bom_version_id = ?', [$ver->id]) === null) {
            $problems['lines'] = 'Add at least one line before submitting.';
        }
        if ($ver->effective_from < $this->today($db)) {
            $problems['effectiveFrom'] = 'The version would take effect in the past. Choose today or a later day.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
        $this->moveTo($db, 'bom_versions', 'bom_version', $ver, 'SUBMITTED', ['submitted_at' => now()->toIso8601String()], 'bom_version.submitted', null);

        return $this->respondVersion($db, (string) $ver->id);
    }

    /** SUBMITTED → DRAFT to change it. */
    public function withdraw(string $version): JsonResponse
    {
        [$db, $ver] = $this->locked($version, self::SUBMIT, 'SUBMITTED');
        $this->moveTo($db, 'bom_versions', 'bom_version', $ver, 'DRAFT', ['submitted_at' => null], 'bom_version.withdrawn', null);

        return $this->respondVersion($db, (string) $ver->id);
    }

    /** SUBMITTED → REJECTED with a reason, by someone who may approve and did not prepare or edit it. */
    public function reject(Request $request, string $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $ver] = $this->locked($version, self::APPROVE, 'SUBMITTED');
        $this->refuseAuthor($db, 'bom_version_contributors', 'bom_version_id', $ver, 'A BOM version is reviewed by someone who did not prepare or edit it.');
        $this->moveTo($db, 'bom_versions', 'bom_version', $ver, 'REJECTED', ['reject_reason' => $data['reason']], 'bom_version.rejected', null);

        return $this->respondVersion($db, (string) $ver->id);
    }

    /** REJECTED → DRAFT to rework it. */
    public function reopen(string $version): JsonResponse
    {
        [$db, $ver] = $this->locked($version, self::SUBMIT, 'REJECTED');
        $this->moveTo($db, 'bom_versions', 'bom_version', $ver, 'DRAFT', ['reject_reason' => null, 'submitted_at' => null], 'bom_version.reopened', null);

        return $this->respondVersion($db, (string) $ver->id);
    }

    /** SUBMITTED → APPROVED by a second person through the database function, which also writes the audit events. */
    public function approve(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::APPROVE);
        $id = $this->existing($db, 'bom_versions', $version);
        try {
            $db->selectOne('select lifecycle_api.approve_bom_version(?) as superseded', [$id]);
        } catch (QueryException $e) {
            throw self::approvalFailure($e, 'A BOM version is approved by someone who did not prepare or edit it.');
        }

        return $this->respondVersion($db, $id);
    }

    /** @param  array<string, mixed>  $line */
    private function checkLine(EoraPostgresConnection $db, stdClass $ver, array $line, int $index, bool $repeated): void
    {
        $field = static fn (string $name) => "lines.{$index}.{$name}";
        $component = strtolower($line['componentItemId']);
        $item = $db->selectOne('select id, base_uom_id, status from items where id = ?', [$component]);
        if ($item === null || $item->status !== 'ACTIVE') {
            throw ValidationException::withMessages([$field('componentItemId') => 'Choose an active item.']);
        }
        if ($item->id === $ver->item_id) {
            throw ValidationException::withMessages([$field('componentItemId') => 'An item is not a component of its own BOM.']);
        }
        $uom = strtolower($line['uomId']);
        $usable = $uom === $item->base_uom_id
            || $db->selectOne('select 1 as ok from uom_conversions where item_id = ? and (from_uom_id = ? or to_uom_id = ?)', [$item->id, $uom, $uom]) !== null;
        if (! $usable) {
            throw ValidationException::withMessages([$field('uomId') => 'Use the component\'s base unit or a unit it has a conversion for.']);
        }
        if ($repeated && self::optionalText($line['linePurpose'] ?? null) === null) {
            throw ValidationException::withMessages([$field('linePurpose') => 'This component is on more than one line: say what this line is for, e.g. "bracket side".']);
        }
    }

    /**
     * The version locked for a change in `$status`, with the permission held in any scope (D-078).
     *
     * @return array{0: EoraPostgresConnection, 1: stdClass}
     */
    private function locked(string $version, string $permission, string $status): array
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'bom_versions', $version);
        $ver = $db->selectOne('select * from bom_versions where id = ? for update', [$id]);
        self::requireStatus($ver, $status, 'version');

        return [$db, $ver];
    }

    /** The version row (list representation) with its lines; ETag = version row version. */
    private function respondVersion(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new BomVersionsQuery, $id) ?? throw new \LogicException('Written row not visible.');
        $row['lines'] = array_map(static fn (stdClass $l) => [
            'id' => $l->id, 'lineNo' => $l->line_no, 'componentItemId' => $l->component_item_id, 'componentCode' => $l->component_code,
            'componentName' => $l->component_name, 'componentType' => $l->component_type, 'grossQtyPer' => $l->gross_qty_per, 'uomId' => $l->uom_id,
            'uomCode' => $l->uom_code, 'scrapAllowance' => $l->scrap_allowance, 'linePurpose' => $l->line_purpose,
        ], $db->select(
            'select l.id, l.line_no, l.component_item_id, i.code as component_code, i.name as component_name, i.item_type as component_type,
                    l.gross_qty_per::text as gross_qty_per, l.uom_id, u.code as uom_code, l.scrap_allowance::text as scrap_allowance, l.line_purpose
               from bom_lines l join items i on i.id = l.component_item_id join uoms u on u.id = l.uom_id
              where l.bom_version_id = ? order by l.line_no',
            [$id],
        ));

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
