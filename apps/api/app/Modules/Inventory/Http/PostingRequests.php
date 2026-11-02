<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Contracts\PostingResult;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Shared request handling of the posting endpoints (doc 24 §5.3): Idempotency-Key header, plant-scoped permission,
 * quantities at the unit's decimals, and the posted document as the answer (201 posted, 200 replayed).
 *
 * @property-read Authorizer $authorizer
 */
trait PostingRequests
{
    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Postings require the EORA PostgreSQL connection.');
    }

    private static function optionalText(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === null || $text === '' ? null : $text;
    }

    /** A quantity in the item's base unit has at most the unit's decimals (the ledger stores it so, BR-INV-10). */
    private static function requireUnitPrecision(string $qty, stdClass $unit, string $field): void
    {
        $dot = strpos($qty, '.');
        $decimals = $dot === false ? 0 : strlen(rtrim(substr($qty, $dot + 1), '0'));
        if ($decimals > (int) $unit->decimals) {
            throw ValidationException::withMessages([$field => (int) $unit->decimals === 0
                ? "Enter a whole number of {$unit->uom}."
                : "Enter {$unit->uom} with at most {$unit->decimals} decimals."]);
        }
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $key) !== 1) {
            throw new DomainProblem(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency key required', 'Send an Idempotency-Key header (8–200 letters, digits, . _ : -).');
        }

        return $key;
    }

    private function plant(EoraPostgresConnection $db, string $plantId, string $permission): string
    {
        $this->authorizer->requireAnywhere($db, $permission);
        $plant = strtolower($plantId);
        if ($db->selectOne('select 1 as ok from plants where id = ?', [$plant]) === null) {
            throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        }
        $this->authorizer->authorize($db, $permission, Scope::plant($plant, (string) $db->selectOne('select company_id from plants where id = ?', [$plant])->company_id));

        return $plant;
    }

    private function answer(EoraPostgresConnection $db, PostingResult $result): JsonResponse
    {
        $lot = $db->selectOne('select l.id, l.lot_no from inventory_movements m join lots l on l.id = m.lot_id where m.document_id = ? limit 1', [$result->documentId]);

        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => $lot?->id, 'lotNo' => $lot?->lot_no]], $result->replayed ? 200 : 201);
    }
}
