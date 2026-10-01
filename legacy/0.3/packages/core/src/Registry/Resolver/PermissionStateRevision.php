<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use AzGuard\Configuration\Config;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Global monotonic permission-state revision on the authorization connection.
 *
 * @internal
 */
class PermissionStateRevision
{
    public const SINGLETON_ID = 1;

    private int $mutationDepth = 0;

    /** @var array<int, bool> */
    private array $changedAtDepth = [];

    /**
     * @template T
     *
     * @param  Closure(): array{0: T, 1: bool}  $operation
     * @return T
     */
    public function mutate(Closure $operation): mixed
    {
        Config::assertAuthorizationConnectionsAligned();

        return $this->connection()->transaction(function () use ($operation): mixed {
            $level = ++$this->mutationDepth;
            $this->changedAtDepth[$level] = false;

            try {
                [$result, $changed] = $operation();

                if ($changed || $this->wasMarkedChanged($level)) {
                    if ($level > 1) {
                        $this->changedAtDepth[$level - 1] = true;
                    } else {
                        $this->bump();
                    }
                }

                return $result;
            } finally {
                unset($this->changedAtDepth[$level]);
                $this->mutationDepth--;
            }
        });
    }

    public function insideMutation(): bool
    {
        return $this->mutationDepth > 0;
    }

    public function markChanged(): void
    {
        if (! $this->insideMutation()) {
            throw new RuntimeException('AzGuard permission-state change must be inside a mutation.');
        }

        $this->changedAtDepth[$this->mutationDepth] = true;
    }

    private function wasMarkedChanged(int $level): bool
    {
        return $this->changedAtDepth[$level] ?? false;
    }

    public function current(): int
    {
        $row = $this->connection()->table($this->table())
            ->where('id', self::SINGLETON_ID)
            ->first();

        if ($row === null) {
            throw new RuntimeException('AzGuard permission-state row is missing.');
        }

        return (int) $row->revision;
    }

    public function bump(): int
    {
        $connection = $this->connection();
        $table = $this->table();

        $row = $connection->table($table)
            ->where('id', self::SINGLETON_ID)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException('AzGuard permission-state row is missing.');
        }

        $next = (int) $row->revision + 1;
        $updated = $connection->table($table)
            ->where('id', self::SINGLETON_ID)
            ->update(['revision' => $next]);

        if ($updated !== 1) {
            throw new RuntimeException('AzGuard permission-state revision bump failed.');
        }

        return $next;
    }

    public function inTransaction(): bool
    {
        try {
            return $this->connection()->transactionLevel() > 0;
        } catch (Throwable $e) {
            throw new RuntimeException('AzGuard cannot determine authorization transaction state.', 0, $e);
        }
    }

    public function connection(): Connection
    {
        $class = Config::roleModel();
        /** @var Model $model */
        $model = new $class;

        return $model->getConnection();
    }

    public function assertSameConnection(Model $model): void
    {
        $state = (string) ($this->connection()->getName() ?? config('database.default'));
        $other = (string) ($model->getConnectionName() ?? config('database.default'));

        if ($state !== $other) {
            throw new RuntimeException(sprintf(
                'AzGuard permission-state connection [%s] does not match model connection [%s].',
                $state,
                $other,
            ));
        }
    }

    public function table(): string
    {
        return Config::permissionStateTable();
    }
}
