<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Closure;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Throwable;

/** Observe failed root transactions without changing their retry or lock behavior. */
trait ObservesTransactions
{
    public function transaction(Closure $callback, $attempts = 1)
    {
        $root = $this->transactionLevel() === 0;

        try {
            return parent::transaction($callback, $attempts);
        } catch (Throwable $error) {
            if ($root) {
                TransactionProbe::failed($error);
            }

            throw $error;
        }
    }
}

final class BenchSqliteConnection extends SQLiteConnection
{
    use ObservesTransactions;
}
final class BenchPostgresConnection extends PostgresConnection
{
    use ObservesTransactions;
}
final class BenchMysqlConnection extends MySqlConnection
{
    use ObservesTransactions;
}
final class BenchMariadbConnection extends MariaDbConnection
{
    use ObservesTransactions;
}
