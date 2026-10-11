<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use App\Platform\Database\EoraPostgresConnection;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Unit of work for all tenant database work (ADR-0001 §6, doc 25 §6.2): the outermost transaction, opened with the
 * transaction binding, then the signed context installed as a transaction-local setting. Commit on success, rollback
 * on any exception. Policies read only ctx_api.context(); nothing else establishes tenant scope.
 */
final class TenantTransaction
{
    public function __construct(private readonly DatabaseManager $db, private readonly ContextSigner $signer) {}

    /**
     * @template T
     *
     * @param  Closure(EoraPostgresConnection): T  $work
     * @return T
     */
    public function run(TenantContext $context, Closure $work, ?string $connection = null): mixed
    {
        $db = $this->db->connection($connection);

        if (! $db instanceof EoraPostgresConnection) {
            throw new LogicException('TenantTransaction requires the EORA PostgreSQL connection.');
        }

        if ($db->transactionLevel() !== 0) {
            // A tenant transaction is always the outermost one: an inner caller would otherwise inherit, or overwrite,
            // another context. Inner work uses DB::transaction(), which becomes a savepoint.
            throw new LogicException('TenantTransaction must be the outermost transaction.');
        }

        return $db->transaction(function () use ($db, $context, $work): mixed {
            $binding = $db->transactionBinding() ?? throw new LogicException('Missing transaction binding.');
            $db->installSignedContext($this->signer->sign($context, $binding['pid'], $binding['xact_us']));

            return $work($db);
        }, 1);
    }
}
