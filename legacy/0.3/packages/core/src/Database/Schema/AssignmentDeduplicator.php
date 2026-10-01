<?php

declare(strict_types=1);

namespace AzGuard\Database\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Throwable;

/**
 * Connection-local, SQL-staged dedupe for assignment pivots.
 *
 * PHP counts metadata only and must not load pivot rows. Destructive writes
 * run in one transaction; index DDL stays with the caller. Temporary names
 * are deterministic and grammar-quoted, and they are removed before a retry
 * and after the attempt. Concurrent writes are outside this contract.
 *
 * @internal
 */
final class AssignmentDeduplicator
{
    private static ?string $fault = null;

    /**
     * Arm a one-shot recovery fault. Points: `before-role-insert`, `before-scope-index`.
     *
     * @internal
     */
    public static function armFault(string $point): void
    {
        self::$fault = $point;
    }

    /**
     * @internal
     */
    public static function resetFault(): void
    {
        self::$fault = null;
    }

    public static function consumeFault(string $point): void
    {
        if (self::$fault !== $point) {
            return;
        }

        self::$fault = null;

        throw new RuntimeException('injected migration fault: '.$point);
    }

    /**
     * @return array{source_rows: int, kept_rows: int, lock_ns: int}
     */
    public function dedupeRoleAssignments(Connection $connection, string $table): array
    {
        $columns = ['role_id', 'model_type', 'model_id'];
        $stage = 'azg_dedupe_model_has_roles';

        return $this->withStage($connection, $stage, function () use ($connection, $table, $columns, $stage): array {
            $this->createStage($connection, $stage, $this->distinctSelectSql($connection, $table, $columns));

            $sourceRows = $this->countTable($connection, $table);
            $keptRows = $this->countTable($connection, $stage);
            $this->assertCardinality($table, $sourceRows, $keptRows, $this->countDistinct($connection, $table, $columns));

            $lockNs = $sourceRows > $keptRows
                ? $this->replaceAll($connection, $table, $stage, $columns)
                : 0;

            return [
                'source_rows' => $sourceRows,
                'kept_rows' => $keptRows,
                'lock_ns' => $lockNs,
            ];
        });
    }

    /**
     * Keep the lowest id of each full nullable identity, including that row's timestamps.
     *
     * @return array{source_rows: int, kept_rows: int, lock_ns: int}
     */
    public function dedupeScopeAssignments(Connection $connection, string $table): array
    {
        $columns = ['model_type', 'model_id', 'scope_entity_type', 'scope_entity_id', 'role_id', 'panel_id'];
        $stage = 'azg_dedupe_model_has_scopes';

        return $this->withStage($connection, $stage, function () use ($connection, $table, $columns, $stage): array {
            $this->createStage($connection, $stage, $this->keepIdSelectSql($connection, $table, $columns));

            $sourceRows = $this->countTable($connection, $table);
            $keptRows = $this->countTable($connection, $stage);
            $missingKeepIds = $this->countMissingKeepIds($connection, $table, $stage);
            $expectedKept = $missingKeepIds === 0
                ? $this->countGroups($connection, $table, $columns)
                : -1;
            $this->assertCardinality($table, $sourceRows, $keptRows, $expectedKept);

            $lockNs = $sourceRows > $keptRows
                ? $this->deleteNonKept($connection, $table, $stage)
                : 0;

            return [
                'source_rows' => $sourceRows,
                'kept_rows' => $keptRows,
                'lock_ns' => $lockNs,
            ];
        });
    }

    /**
     * @param  callable(): array{source_rows: int, kept_rows: int, lock_ns: int}  $callback
     * @return array{source_rows: int, kept_rows: int, lock_ns: int}
     */
    private function withStage(Connection $connection, string $stage, callable $callback): array
    {
        $this->dropStage($connection, $stage);

        try {
            return $callback();
        } finally {
            $this->dropStage($connection, $stage);
        }
    }

    private function createStage(Connection $connection, string $stage, string $selectSql): void
    {
        $temporary = match ($connection->getDriverName()) {
            'mysql' => 'TEMPORARY',
            'pgsql', 'sqlite' => 'TEMP',
            default => throw new RuntimeException(
                'Assignment dedupe does not support driver ['.$connection->getDriverName().'].',
            ),
        };

        $connection->statement(sprintf(
            'CREATE %s TABLE %s AS %s',
            $temporary,
            $this->wrapTable($connection, $stage),
            $selectSql,
        ));
    }

    private function dropStage(Connection $connection, string $stage): void
    {
        $quoted = $this->wrapTable($connection, $stage);
        $sql = match ($connection->getDriverName()) {
            'mysql' => "DROP TEMPORARY TABLE IF EXISTS {$quoted}",
            'pgsql', 'sqlite' => "DROP TABLE IF EXISTS {$quoted}",
            default => throw new RuntimeException(
                'Assignment dedupe does not support driver ['.$connection->getDriverName().'].',
            ),
        };

        $connection->statement($sql);
    }

    /**
     * @param  list<string>  $columns
     */
    private function distinctSelectSql(Connection $connection, string $table, array $columns): string
    {
        return sprintf(
            'SELECT DISTINCT %s FROM %s',
            implode(', ', array_map(fn (string $column): string => $this->wrapColumn($connection, $column), $columns)),
            $this->wrapTable($connection, $table),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function keepIdSelectSql(Connection $connection, string $table, array $columns): string
    {
        $id = $this->wrapColumn($connection, 'id');

        return sprintf(
            'SELECT MIN(%s) AS %s FROM %s GROUP BY %s',
            $id,
            $id,
            $this->wrapTable($connection, $table),
            implode(', ', array_map(fn (string $column): string => $this->wrapColumn($connection, $column), $columns)),
        );
    }

    private function countTable(Connection $connection, string $table): int
    {
        return (int) $connection->table($table)->count();
    }

    /**
     * @param  list<string>  $columns
     */
    private function countDistinct(Connection $connection, string $table, array $columns): int
    {
        return (int) $connection->query()->fromSub(
            $connection->table($table)->select($columns)->distinct(),
            'azg_distinct_assignments',
        )->count();
    }

    /**
     * @param  list<string>  $columns
     */
    private function countGroups(Connection $connection, string $table, array $columns): int
    {
        return (int) $connection->query()->fromSub(
            $connection->table($table)->select($columns)->groupBy($columns),
            'azg_assignment_groups',
        )->count();
    }

    private function countMissingKeepIds(Connection $connection, string $table, string $stage): int
    {
        return (int) $connection->table($stage, 'azg_keep')
            ->leftJoin($table.' as azg_src', 'azg_src.id', '=', 'azg_keep.id')
            ->whereNull('azg_src.id')
            ->count();
    }

    private function assertCardinality(string $table, int $sourceRows, int $keptRows, int $expectedKept): void
    {
        if ($keptRows !== $expectedKept || $keptRows > $sourceRows || ($sourceRows > 0 && $keptRows === 0)) {
            throw new RuntimeException(sprintf(
                'Assignment dedupe refused to modify [%s]: source=%d staged=%d expected=%d.',
                $table,
                $sourceRows,
                $keptRows,
                $expectedKept,
            ));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceAll(Connection $connection, string $table, string $stage, array $columns): int
    {
        $started = hrtime(true);

        $this->runTransactional($connection, function () use ($connection, $table, $stage, $columns): void {
            $connection->table($table)->delete();
            self::consumeFault('before-role-insert');
            $connection->table($table)->insertUsing(
                $columns,
                $connection->table($stage)->select($columns),
            );
        });

        return hrtime(true) - $started;
    }

    private function deleteNonKept(Connection $connection, string $table, string $stage): int
    {
        $started = hrtime(true);

        $this->runTransactional($connection, function () use ($connection, $table, $stage): void {
            $connection->table($table)->whereNotExists(function (Builder $query) use ($table, $stage): void {
                $query->selectRaw('1')
                    ->from($stage)
                    ->whereColumn($stage.'.id', '=', $table.'.id');
            })->delete();
        });

        return hrtime(true) - $started;
    }

    /**
     * MySQL DDL implicitly commits, so a surrounding Laravel transaction can
     * be stale: the counter stays above zero while PDO is not in a transaction
     * and a savepoint rollback cannot restore rows. Open a real transaction
     * in that case.
     *
     * @param  callable(): void  $callback
     */
    private function runTransactional(Connection $connection, callable $callback): void
    {
        $pdo = $connection->getPdo();

        if ($connection->transactionLevel() > 0 && ! $pdo->inTransaction()) {
            $pdo->beginTransaction();

            try {
                $callback();
                $pdo->commit();
            } catch (Throwable $exception) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // The driver already ended the transaction.
                }

                throw $exception;
            }

            return;
        }

        $connection->transaction(function () use ($callback): null {
            $callback();

            return null;
        });
    }

    private function wrapTable(Connection $connection, string $table): string
    {
        return $connection->getQueryGrammar()->wrapTable($table);
    }

    private function wrapColumn(Connection $connection, string $column): string
    {
        return $connection->getQueryGrammar()->wrap($column);
    }
}
