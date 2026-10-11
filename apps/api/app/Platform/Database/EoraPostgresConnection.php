<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;

/**
 * PostgreSQL connection whose outermost BEGIN also reads the transaction binding (backend pid, transaction start in
 * µs) in the same round trip (D-055). The statement is constant SQL without parameters and without secrets, so it may
 * travel as one simple-protocol query; every other statement keeps native server-side prepares (D-B1-09).
 */
class EoraPostgresConnection extends PostgresConnection
{
    private const string BEGIN_AND_BIND = 'BEGIN; SELECT pg_backend_pid() AS pid, '
        .'(EXTRACT(epoch FROM transaction_timestamp()) * 1000000)::bigint::text AS xact_us';

    /** @var array{pid: int, xact_us: string}|null */
    private ?array $binding = null;

    protected function executeBeginTransactionStatement(): void
    {
        $this->binding = null;
        $statement = $this->getPdo()->prepare(self::BEGIN_AND_BIND, [PDO::ATTR_EMULATE_PREPARES => true]);
        $statement->execute();
        /** @var array{pid: int|string, xact_us: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if ($row === false || ! $this->getPdo()->inTransaction()) {
            throw new RuntimeException('BEGIN with transaction binding did not open a transaction.');
        }

        $this->binding = ['pid' => (int) $row['pid'], 'xact_us' => (string) $row['xact_us']];
    }

    /**
     * Binding of the current outermost transaction; null outside a transaction.
     *
     * @return array{pid: int, xact_us: string}|null
     */
    public function transactionBinding(): ?array
    {
        return $this->transactionLevel() > 0 ? $this->binding : null;
    }

    /**
     * Installs the signed token for this transaction. Executed directly on PDO with a bound parameter so the token
     * never reaches Laravel's query events, query log or exception messages.
     */
    public function installSignedContext(string $token): void
    {
        $statement = $this->getPdo()->prepare("SELECT set_config('app.ctx', ?, true)");
        $statement->execute([$token]);
        $statement->closeCursor();
    }
}
