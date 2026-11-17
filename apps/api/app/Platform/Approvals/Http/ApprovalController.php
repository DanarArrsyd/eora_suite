<?php

declare(strict_types=1);

namespace App\Platform\Approvals\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Approvals\Approvals;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who decides a document waiting for a second person (D-129): the screen of the requester says it, by role and by name,
 * so nobody guesses whom to ask. Read with the view permission of the document's kind.
 */
final class ApprovalController
{
    public function __construct(private readonly Approvals $approvals, private readonly Authorizer $authorizer) {}

    /**
     * `kind` is one of routing_version, bom_version, bom_routing_map, item_weight, conversion_profile_version,
     * mps_version, work_order, stock_adjustment, reversal, quality_release, wo_close_exception, period_close,
     * period_reopen.
     */
    public function show(string $kind, string $id): JsonResponse
    {
        if (! in_array($kind, Approvals::kinds(), true) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new NotFoundHttpException;
        }
        $db = $this->db();
        $permission = Approvals::viewPermission($kind);
        // nothing is looked up for a caller without the view permission anywhere
        $this->authorizer->requireAnywhere($db, $permission);
        [$exists, $plant] = $this->approvals->locate($db, $kind, $id);
        if (! $exists) {
            throw new NotFoundHttpException;
        }
        // a plant document is shown only within the caller's scope of that plant; outside it, it does not exist
        if ($plant !== null) {
            try {
                $this->authorizer->authorize($db, $permission, Scope::plant($plant, (string) $db->selectOne('select company_id from plants where id = ?', [$plant])->company_id));
            } catch (DomainProblem) {
                throw new NotFoundHttpException;
            }
        }

        return response()->json(['data' => $this->approvals->status($db, $kind, $id)]);
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Approvals require the EORA PostgreSQL connection.');
    }
}
