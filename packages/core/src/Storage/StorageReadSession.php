<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Reads;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use JsonException;
use PDO;

/** @internal One consumed authority fence, including schema validation, on one pinned PDO. */
final class StorageReadSession
{
    private readonly Connection $connection;

    private readonly PDO $pdo;

    private bool $schemaChecked = false;

    /** @param Closure(string, ?string): Model $models */
    public function __construct(private readonly Storage $storage, private readonly Reads $reads, private readonly Closure $models)
    {
        $this->assertNoTransaction();
        $authority = $storage->connection();
        $pdo = $reads === Reads::Primary ? $authority->getPdo() : $authority->getRawReadPdo();

        if ($reads === Reads::Default && $pdo === null) {
            if ($authority->getConfig('read') !== null) {
                throw InvalidConfigurationException::failing('authority_read', 'Configured authority read route has no PDO.');
            }
            // An unsplit connection has one configured route for both kinds of reads.
            $pdo = $authority->getPdo();
        }

        if ($pdo instanceof Closure) {
            $pdo = $pdo();

            if ($pdo instanceof PDO) {
                $authority->setReadPdo($pdo);
            }
        }

        if (! $pdo instanceof PDO) {
            throw InvalidConfigurationException::failing('authority_read', 'Authority read route did not resolve a PDO.');
        }
        $this->pdo = $pdo;
        $this->assertNoTransaction($pdo);
        $this->connection = clone $authority;
        $this->connection->setPdo($pdo)->setReadPdo($pdo);
        $this->connection->setReconnector(static function (): never {
            throw InvalidConfigurationException::failing('authority_read', 'A pinned authority read cannot reconnect during its fence.');
        });
    }

    public function table(string $base): Builder
    {
        $this->assertNoTransaction($this->pdo);

        return $this->connection->table($this->storage->prefix().$base);
    }

    /** Stable route namespace; credentials are never retained in a cache entry. */
    public function authorityIdentity(): string
    {
        $connection = $this->storage->connection();

        return IdentityCodec::digest([$this->storage->id(), $this->storage->connectionName(), $this->storage->prefix(),
            $this->storage->hostKeys(), $this->reads->value, (string) json_encode(array_intersect_key($connection->getConfig(),
                array_flip(['driver', 'database', 'host', 'port', 'unix_socket', 'read', 'write'])), JSON_THROW_ON_ERROR)]);
    }

    /** Request memo is also tied to the currently resolved physical handle. */
    public function handleIdentity(): int
    {
        return spl_object_id($this->pdo);
    }

    public function assertUsable(): void
    {
        $this->assertNoTransaction($this->pdo);
    }

    public function model(string $kind, ?string $class = null): Model
    {
        $model = ($this->models)($kind, $class);
        $this->assertSchema();

        return $model;
    }

    public function state(string $panel): ?PanelState
    {
        PermissionGrammar::assertPanelId($panel);
        $this->assertSchema();
        $row = $this->table('panel_state')->where('panel', $panel)->first();

        return $row === null ? null : new PanelState($row->panel, (int) $row->version, $row->incarnation,
            new DateTimeImmutable($row->updated_at, new DateTimeZone('UTC')));
    }

    private function assertNoTransaction(?PDO $readPdo = null): void
    {
        $connection = $this->storage->connection();
        $write = $connection->getRawPdo();

        if ($connection->transactionLevel() > 0 || ($write instanceof PDO && $write->inTransaction())
            || ($readPdo !== null && $readPdo->inTransaction())) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Consumed authority reads cannot run inside an unrecognized transaction.');
        }
    }

    private function assertSchema(): void
    {
        $this->assertNoTransaction($this->pdo);

        if ($this->schemaChecked) {
            return;
        }
        $expected = $this->storage->schema();
        $found = null;

        try {
            $row = $this->table('storage_state')->where('id', 1)->first();

            if ($row !== null) {
                $found = json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR);
            }
        } catch (QueryException|JsonException $error) {
            throw new StorageMismatchException('Storage '.$this->storage->id().' expected '.json_encode($expected).'; cannot read storage_state: '.$error->getMessage(), 0, $error);
        }

        if (! is_array($found) || count($found) !== count($expected)
            || array_filter($expected, static fn (int|string $value, string $key): bool => ($found[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
            throw new StorageMismatchException('Storage '.$this->storage->id().' expected '.json_encode($expected).'; found '.json_encode($found).'.');
        }
        $this->schemaChecked = true;
    }
}
