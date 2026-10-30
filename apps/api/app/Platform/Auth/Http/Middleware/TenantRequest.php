<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http\Middleware;

use App\Platform\Auth\AuthSession;
use App\Platform\Auth\SessionExpiredException;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Business-route unit of work (doc 25 §6.2): the whole request runs inside one TenantTransaction with the signed
 * context of the session's user and active tenant. Inside it, the user's credential version and ACTIVE membership
 * are re-checked under RLS (R-39). GET runs read-only. 2xx/3xx commit; any 4xx/5xx response rolls back. A route that
 * opts in with `tenant:retry` runs again, up to three times, after a deadlock or a serialization failure (ADR-0014
 * §3) — only routes whose work is idempotent and has no effect outside the transaction (ledger postings).
 */
final class TenantRequest
{
    public function __construct(private readonly AuthSession $auth, private readonly TenantTransaction $transaction) {}

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $user = $this->auth->requireUser(); // includes the absolute lifetime (D-B1-05)
        $tenant = $this->auth->tenantId() ?? throw new AccessDeniedHttpException('No active tenant.');

        try {
            return $this->transaction->run(new TenantContext($tenant, $user), function (EoraPostgresConnection $db) use ($request, $next, $user): Response {
                if ($request->isMethodSafe()) {
                    $db->statement('set local transaction_read_only = on');
                }
                $row = $db->selectOne(
                    "select u.credential_version from users u join memberships m on m.user_id = u.id and m.status = 'ACTIVE' where u.id = ?",
                    [$user],
                );
                if ($row === null || (int) $row->credential_version !== $this->auth->credentialVersion()) {
                    throw new SessionExpiredException('Session no longer valid.');
                }

                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new RollbackWithResponse($response);
                }

                return $response;
            }, attempts: in_array('retry', $options, true) && ! $request->isMethodSafe() ? 3 : 1);
        } catch (RollbackWithResponse $rollback) {
            return $rollback->response;
        } catch (SessionExpiredException $e) {
            $this->auth->end();

            throw $e;
        }
    }
}
