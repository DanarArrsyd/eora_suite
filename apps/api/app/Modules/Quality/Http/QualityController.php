<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Modules\Inventory\Http\ReceiptController;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Quality → disposition of output waiting for inspection (doc 33 §1.5, D-113, doc 04 row 9, BR-QC-01..03).
 *
 * - Disposition (`quality.inspection.post`, Idempotency-Key): part or all of one lot waiting at the QC area is released
 *   (QC area QC_RELEASED), held (QC area HOLD), sent to rework (NG area REWORK) or rejected (NG area REJECTED); hold,
 *   rework and reject give a reason code (OTHER with a note); the rest keeps waiting. A HOLD quantity may be rejected.
 *   Never by who reported that lot's output (SoD-01).
 * - Release out of policy (`quality.inspection.submit`): releasing a HOLD quantity, or a REJECTED one back to released,
 *   is asked with the reason and decided by a second person (`quality.disposition_release.approve`, QCS).
 */
final class QualityController
{
    public const array OUTCOMES = ['RELEASE', 'HOLD', 'REWORK', 'REJECT'];

    /** D-113 (tenant configuration later); OTHER needs a note. */
    public const array REASONS = ['DIMENSION', 'VISUAL', 'BURR', 'CRACK', 'MATERIAL', 'OTHER'];

    private const array CODE = ['RELEASE' => 'QC_RELEASE', 'HOLD' => 'QC_HOLD', 'REWORK' => 'QC_REWORK', 'REJECT' => 'QC_REJECT'];

    private const array STATUS = ['RELEASE' => 'QC_RELEASED', 'HOLD' => 'HOLD', 'REWORK' => 'REWORK', 'REJECT' => 'REJECTED'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Decide part or all of one stock line waiting at the QC area (Idempotency-Key header required). 201 when posted, 200
     * when the same request was posted before under the key, 409 when the key was used for another request.
     */
    public function dispose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stockLineId' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:4'],
            'lines.*.outcome' => ['required', Rule::in(QualityController::OUTCOMES)],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'lines.*.reasonCode' => ['nullable', Rule::in(QualityController::REASONS)],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            'ngLocationId' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $line = $this->stockLine($db, $data['stockLineId'], 'quality.inspection.post');
        $hash = hash('sha256', json_encode(['QC_DISPOSITION', $line->id, $data], JSON_THROW_ON_ERROR));
        // a retried request answers with what it posted, whatever the stock did since
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->posted($previous);
        }

        if ($line->location_type !== 'QC_AREA' || ! in_array($line->stock_status, ['PENDING_QC', 'HOLD'], true)) {
            throw ValidationException::withMessages(['stockLineId' => 'Choose a lot waiting at the QC area (pending QC or on hold).']);
        }
        $this->requireNotReporter($db, $line);
        $seen = [];
        $legs = [];
        $ng = null;
        foreach (array_values($data['lines']) as $i => $l) {
            $outcome = (string) $l['outcome'];
            if (isset($seen[$outcome])) {
                throw ValidationException::withMessages(["lines.{$i}.outcome" => 'Enter each outcome once.']);
            }
            $seen[$outcome] = true;
            // BR-QC-02: from HOLD only reject in policy; its release is asked of a second person
            if ($line->stock_status === 'HOLD' && $outcome !== 'REJECT') {
                throw ValidationException::withMessages(["lines.{$i}.outcome" => 'A quantity on hold is rejected here; its release is asked of a second person.']);
            }
            $this->precision($l['qty'], $line, "lines.{$i}.qty");
            $reason = $l['reasonCode'] ?? null;
            $note = self::text($l['note'] ?? null);
            if ($outcome === 'RELEASE' && $reason !== null) {
                throw ValidationException::withMessages(["lines.{$i}.reasonCode" => 'A release gives no reason code.']);
            }
            if ($outcome !== 'RELEASE' && $reason === null) {
                throw ValidationException::withMessages(["lines.{$i}.reasonCode" => 'Choose the reason.']);
            }
            if ($reason === 'OTHER' && mb_strlen((string) $note) < 3) {
                throw ValidationException::withMessages(["lines.{$i}.note" => 'Explain it when the reason is Other.']);
            }
            $to = (string) $line->location_id;
            if (in_array($outcome, ['REWORK', 'REJECT'], true)) {
                $ng ??= $this->ngArea($db, (string) $line->plant_id, $data['ngLocationId'] ?? null);
                $to = $ng;
            }
            $code = self::CODE[$outcome];
            $legs[] = new PostingLeg($code, (string) $line->location_id, (string) $line->item_id, $line->lot_id, (string) $line->stock_status, '-'.$l['qty']);
            $legs[] = new PostingLeg($code, $to, (string) $line->item_id, $line->lot_id, self::STATUS[$outcome], $l['qty'], reasonCode: $reason, reasonNote: $note);
        }

        return $this->posted($this->postings->post($db, new PostingCommand('QC_DISPOSITION', (string) $line->plant_id, $key, $legs,
            note: self::text($data['note'] ?? null), requestHash: $hash)));
    }

    /**
     * Ask for a release out of policy (Idempotency-Key header required): a HOLD quantity at the QC area, or a REJECTED one
     * at the NG area, back to QC_RELEASED at the QC area. Nothing moves until a second person approves it.
     */
    public function requestRelease(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stockLineId' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'qcLocationId' => ['nullable', 'uuid'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $line = $this->stockLine($db, $data['stockLineId'], 'quality.inspection.submit');
        $hash = hash('sha256', json_encode(['QC_RELEASE_REQUEST', $line->id, $data], JSON_THROW_ON_ERROR));
        $previous = $this->postings->requested($db, $key, 'QC_DISPOSITION', $hash);
        if ($previous !== null) {
            return response()->json(['data' => ['documentId' => $previous, 'replayed' => true, 'status' => 'SUBMITTED']]);
        }

        $outOfPolicy = ($line->location_type === 'QC_AREA' && $line->stock_status === 'HOLD') || ($line->location_type === 'NG_AREA' && $line->stock_status === 'REJECTED');
        if (! $outOfPolicy) {
            throw ValidationException::withMessages(['stockLineId' => 'Choose a quantity on hold at the QC area or rejected at the NG area.']);
        }
        $this->requireNotReporter($db, $line);
        $this->precision($data['qty'], $line, 'qty');
        if (bccomp(self::num((string) $line->qty), self::num($data['qty']), 6) < 0) {
            throw ValidationException::withMessages(['qty' => 'Only '.$this->plain((string) $line->qty)." {$line->uom} is there."]);
        }
        $qc = $line->location_type === 'QC_AREA' ? (string) $line->location_id : $this->area($db, (string) $line->plant_id, 'QC_AREA', $data['qcLocationId'] ?? null, 'qcLocationId');
        $result = $this->postings->submit($db, new PostingCommand('QC_DISPOSITION', (string) $line->plant_id, $key, [
            new PostingLeg('QC_RELEASE', (string) $line->location_id, (string) $line->item_id, $line->lot_id, (string) $line->stock_status, '-'.$data['qty']),
            new PostingLeg('QC_RELEASE', $qc, (string) $line->item_id, $line->lot_id, 'QC_RELEASED', $data['qty']),
        ], note: trim($data['reason']), requestHash: $hash), 'OUT_OF_POLICY');

        return response()->json(['data' => $result + ['status' => 'SUBMITTED']], $result['replayed'] ? 200 : 201);
    }

    /** Approve a release out of policy (Idempotency-Key header required): posted now, by the second person. */
    public function approve(Request $request, string $release): JsonResponse
    {
        $key = $this->key($request);
        $db = $this->db();
        $id = $this->releaseRequest($db, $release);
        // SoD-01 for the approver too: who reported the lot's output does not release it
        $this->requireNotReporter($db, $db->selectOne(
            "select request -> 'legs' -> 0 ->> 'lotId' as lot_id, request -> 'legs' -> 0 ->> 'itemId' as item_id, plant_id from inventory_documents where id = ?",
            [$id],
        ) ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found'));

        return $this->posted($this->postings->approve($db, $id, $key));
    }

    /** Reject a release out of policy with the reason; nothing moves. 204, also when it was already rejected. */
    public function reject(Request $request, string $release): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $this->postings->reject($db, $this->releaseRequest($db, $release), trim($data['reason']));

        return response()->noContent();
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Postings require the EORA PostgreSQL connection.');
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $key) !== 1) {
            throw new DomainProblem(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency key required', 'Send an Idempotency-Key header (8–200 letters, digits, . _ : -).');
        }

        return $key;
    }

    /** The stock line with its place and unit, when the actor holds $permission in its plant (404 otherwise unknown). */
    private function stockLine(EoraPostgresConnection $db, string $id, string $permission): stdClass
    {
        $line = $db->selectOne(
            'select b.id, b.plant_id, b.location_id, b.item_id, b.lot_id, b.stock_status, trim_scale(b.qty)::text as qty, l.location_type, u.code as uom, u.decimals, p.company_id
               from stock_balances b join locations l on l.id = b.location_id join items i on i.id = b.item_id join uoms u on u.id = i.base_uom_id
               join plants p on p.id = b.plant_id where b.id = ?',
            [strtolower($id)],
        ) ?? throw ValidationException::withMessages(['stockLineId' => 'Unknown stock line.']);
        $this->authorizer->authorize($db, $permission, Scope::plant((string) $line->plant_id, (string) $line->company_id));

        return $line;
    }

    /**
     * SoD-01, BR-QC-03: who reported this lot's output — or the output of the lot it was split from — does not decide its
     * quality (the database refuses it too). Output
     * without a lot (item not lot-controlled) shares one balance per item: whoever reported any output of that item in the
     * plant without a lot does not decide it.
     */
    private function requireNotReporter(EoraPostgresConnection $db, stdClass $line): void
    {
        // a lot split off keeps its origin: climb LOT_SPLIT edges only (consumed inputs are not this lot's output)
        $reporter = $db->selectOne(
            "with recursive line (lot_id) as (
               select ?::uuid where ?::uuid is not null
               union
               select g.from_lot_id from line join genealogy_edges g on g.to_lot_id = line.lot_id
                 join inventory_documents gd on gd.id = g.document_id and gd.doc_type = 'LOT_SPLIT')
             select 1 as ok from inventory_movements m join inventory_documents d on d.id = m.document_id
              where m.movement_code in ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') and m.qty_signed > 0
                and (m.lot_id in (select lot_id from line) or (?::uuid is null and m.lot_id is null and m.item_id = ? and d.plant_id = ?))
                and d.posted_by = (select c.actor from ctx_api.context() c) limit 1",
            [$line->lot_id, $line->lot_id, $line->lot_id, $line->item_id, $line->plant_id],
        );
        if ($reporter !== null) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Second person required', 'You reported this lot’s output; another person decides its quality.');
        }
    }

    private function ngArea(EoraPostgresConnection $db, string $plant, ?string $id): string
    {
        return $this->area($db, $plant, 'NG_AREA', $id, 'ngLocationId');
    }

    private function area(EoraPostgresConnection $db, string $plant, string $type, ?string $id, string $field): string
    {
        $row = $id !== null
            ? $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = ? and status = 'ACTIVE' for share", [strtolower($id), $plant, $type])
            : $db->selectOne("select id from locations where plant_id = ? and location_type = ? and status = 'ACTIVE' order by code limit 1 for share", [$plant, $type]);

        return (string) ($row ?? throw ValidationException::withMessages([$field => "Choose an active {$type} location of this plant."]))->id;
    }

    /** The release request, when the actor approves releases in its plant (404 for anything else). */
    private function releaseRequest(EoraPostgresConnection $db, string $id): string
    {
        $id = strtolower($id);
        $doc = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne("select d.plant_id, p.company_id from inventory_documents d join plants p on p.id = d.plant_id where d.id = ? and d.doc_type = 'QC_DISPOSITION' and d.requested_by is not null", [$id])
            : null;
        if ($doc === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->authorizer->authorize($db, 'quality.disposition_release.approve', Scope::plant((string) $doc->plant_id, (string) $doc->company_id));

        return $id;
    }

    private function posted(PostingResult $result): JsonResponse
    {
        // the posting answer of doc 24 §5.3; a disposition keeps the lot it decides
        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => null, 'lotNo' => null]], $result->replayed ? 200 : 201);
    }

    private function precision(string $qty, stdClass $unit, string $field): void
    {
        $dot = strpos($qty, '.');
        $decimals = $dot === false ? 0 : strlen(rtrim(substr($qty, $dot + 1), '0'));
        if ($decimals > (int) $unit->decimals) {
            throw ValidationException::withMessages([$field => (int) $unit->decimals === 0 ? "Enter a whole number of {$unit->uom}." : "Enter {$unit->uom} with at most {$unit->decimals} decimals."]);
        }
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === null || $text === '' ? null : $text;
    }

    /** @return numeric-string */
    private static function num(string $value): string
    {
        return is_numeric($value) ? $value : throw new LogicException("Not a quantity: {$value}");
    }

    private function plain(string $qty): string
    {
        return str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty;
    }
}
