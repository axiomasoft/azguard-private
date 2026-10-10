<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Support\Narrow;
use AzGuard\Panels\Reads;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use JsonException;
use PDO;
use Throwable;

/** @internal One consumed authority fence, including schema validation, on one pinned PDO. */
final class StorageReadSession
{
    private readonly Connection $connection;

    private readonly PDO $pdo;

    private bool $schemaChecked = false;

    private readonly ?AuthorityTransaction $transaction;

    private readonly ?AuthorityReadBaseline $baseline;

    private readonly ?string $baselineIdentity;

    private bool $inSnapshot = false;

    /** @param Closure(string, ?string): Model $models */
    public function __construct(private readonly Storage $storage, private readonly Reads $reads, private readonly Closure $models)
    {
        $this->transaction = $storage->authorityTransaction();
        $this->baseline = app()->bound(AuthorityReadBaseline::class) ? app(AuthorityReadBaseline::class) : null;
        $this->baselineIdentity = $this->baseline?->identity($storage);
        $this->assertNoTransaction();
        $authority = $storage->connection();

        // A connection closed by DB::disconnect() (before a fork, between Octane requests) reopens as a query would.
        if ($authority->getRawPdo() === null) {
            $authority->reconnect();
        }
        $pdo = $this->transaction !== null || $this->baselineIdentity !== null || $reads === Reads::Primary ? $authority->getPdo() : $authority->getRawReadPdo();

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

    /**
     * Drops the pinned handle from the private connection when the session ends. Since Laravel 12 a cloned connection
     * and its grammar reference each other, so the clone is only freed by the cycle collector; until then it would keep
     * the PDO, and with it the server connection, open after the host closed it with `DB::disconnect()` (before a fork,
     * between Octane requests, at the end of a test). Batch owners explicitly release their session references in
     * finally: a batch and its observer frames can otherwise retain the session through a cycle.
     */
    public function __destruct()
    {
        // Not called when the constructor threw, so the clone is always set here.
        $this->connection->setPdo(null)->setReadPdo(null);
    }

    /**
     * Whether reads can run in a read-only snapshot transaction on the pinned handle: no transaction of the package
     * (tentative authority) or of a test baseline is open there. Those keep their own handling.
     */
    public function canSnapshot(): bool
    {
        return $this->transaction === null && $this->baselineIdentity === null && ! $this->inSnapshot;
    }

    /**
     * Runs `$read` in one read-only snapshot transaction on the pinned PDO (audits/2026-10-09-consistency-design.md,
     * step 2), so every row it reads belongs to one committed state:
     *
     * - PostgreSQL: REPEATABLE READ READ ONLY, one snapshot from the first statement
     *   (https://www.postgresql.org/docs/16/transaction-iso.html);
     * - MySQL/MariaDB: SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, then START TRANSACTION READ ONLY: a consistent
     *   read view (https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html);
     * - SQLite: BEGIN DEFERRED; a read transaction sees one snapshot (https://sqlite.org/isolation.html). Without WAL a
     *   writer waits for the reader to finish; the doctor warns about it, WAL is never enabled here.
     *
     * `$read` reads plain rows and may look up cached contributions: no model hydration, policy or hook runs inside. The transaction is
     * committed after `$read` returns and rolled back when it throws; when that cleanup fails the connection is
     * disconnected (never reused in an unknown transaction state, never reconnected here).
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  list<self>  $participants  logical sessions sharing this physical handle
     * @return T
     */
    public function snapshot(Closure $read, array $participants = []): mixed
    {
        $sessions = [spl_object_id($this) => $this];
        foreach ($participants as $session) {
            $sessions[spl_object_id($session)] = $session;
        }
        foreach ($sessions as $session) {
            if (! $session->canSnapshot() || $session->pdo !== $this->pdo) {
                throw InvalidConfigurationException::failing('authority_transaction', 'Snapshot participants need the same handle without an open transaction.');
            }
            $session->assertNoTransaction($session->pdo);
        }
        $driver = $this->connection->getDriverName();
        match ($driver) {
            'pgsql' => $this->pdo->exec('START TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'),
            'mysql', 'mariadb' => $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false ? false : $this->pdo->exec('START TRANSACTION READ ONLY'),
            'sqlite' => $this->pdo->exec('BEGIN DEFERRED'),
            default => throw InvalidConfigurationException::failing('authority_read', 'Snapshot reads are not supported on driver '.$driver.'.'),
        };
        foreach ($sessions as $session) {
            $session->inSnapshot = true;
        }
        $committed = false;

        try {
            $result = $read();
            foreach ($sessions as $session) {
                $session->assertOwnSnapshot();
            }
            $this->pdo->exec('COMMIT');
            $committed = true;

            return $result;
        } finally {
            foreach ($sessions as $session) {
                $session->inSnapshot = false;
            }

            try {
                if (! $committed && $this->pdo->inTransaction()) {
                    $this->pdo->exec('ROLLBACK');
                }
            } catch (Throwable) {
                // Reported by the discard below; the original exception of the read stays the one thrown.
            }

            if ($this->pdo->inTransaction()) {
                $this->storage->connection()->disconnect();
            }
        }
    }

    public function table(string $base): Builder
    {
        $this->assertNoTransaction($this->pdo);

        return $this->connection->table($this->storage->prefix().$base);
    }

    /** Stable route namespace; credentials are never retained in a cache entry. */
    public function authorityIdentity(): string
    {
        $config = $this->storage->connection()->getConfig();

        $identity = [$this->storage->id(), $this->storage->connectionName(), $this->storage->prefix(),
            $this->storage->hostKeys(), $this->reads->value, json_encode(array_intersect_key(is_array($config) ? $config : [],
                array_flip(['driver', 'database', 'host', 'port', 'unix_socket', 'read', 'write'])), JSON_THROW_ON_ERROR)];

        if ($this->baselineIdentity !== null) {
            $identity[] = $this->baselineIdentity;
        }

        return IdentityCodec::digest($identity);
    }

    /** Request memo is also tied to the currently resolved physical handle. */
    public function handleIdentity(): int
    {
        return spl_object_id($this->pdo);
    }

    public function transaction(): ?AuthorityTransaction
    {
        $this->assertUsable();

        return $this->transaction;
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
        $this->storage->authorityTransaction($panel);
        $this->assertSchema();
        $row = $this->table('panel_state')->where('panel', $panel)->first();

        return $row === null ? null : PanelState::fromRow($row);
    }

    /**
     * The panel state and the revision of one subject in one statement: `panel_state` LEFT JOIN `subject_revisions`
     * (a missing revision row is revision 0). A cache hit takes its observed state from this statement, never from the
     * cache entry (audits/2026-10-09-consistency-design.md, step 4).
     *
     * @return array{?PanelState, int}
     */
    public function observed(string $panel, string $subjectType, string $subjectId): array
    {
        PermissionGrammar::assertPanelId($panel);
        $this->storage->authorityTransaction($panel);
        $this->assertSchema();
        $row = $this->table('panel_state as ps')
            ->leftJoin($this->storage->prefix().'subject_revisions as sr', static function (JoinClause $join) use ($subjectType, $subjectId): void {
                $join->on('sr.panel', '=', 'ps.panel')->where('sr.subject_type', '=', $subjectType)->where('sr.subject_id', '=', $subjectId);
            })
            ->where('ps.panel', $panel)
            ->first(['ps.panel', 'ps.version', 'ps.incarnation', 'ps.updated_at', 'ps.epoch', 'sr.revision']);

        return $row === null ? [null, 0] : [PanelState::fromRow($row), Narrow::int($row->revision ?? 0, 'subject_revisions.revision')];
    }

    /**
     * Subject ids per IN list of {@see observedMany()}: below the bound-parameter limit of every supported driver
     * (SQLite before 3.32 allows 999, PostgreSQL and MySQL 65535) with room for the other bindings of the statement.
     */
    public const int OBSERVED_CHUNK = 500;

    /**
     * The panel state and the revisions of many subjects of one type: the statement of {@see observed()} with an IN
     * list, one statement per {@see OBSERVED_CHUNK} subjects. Inside one snapshot every chunk sees the same state
     * (audits/2026-10-09-consistency-design.md, step 5).
     *
     * @param  list<string>  $subjectIds
     * @return array{?PanelState, array<string, int>} revisions by subject id; a missing id is revision 0
     */
    public function observedMany(string $panel, string $subjectType, array $subjectIds): array
    {
        PermissionGrammar::assertPanelId($panel);
        $this->storage->authorityTransaction($panel);
        $this->assertSchema();
        [$state, $revisions] = [null, []];
        foreach (array_chunk(array_values(array_unique($subjectIds)), self::OBSERVED_CHUNK) as $chunk) {
            $rows = $this->table('panel_state as ps')
                ->leftJoin($this->storage->prefix().'subject_revisions as sr', static function (JoinClause $join) use ($subjectType, $chunk): void {
                    $join->on('sr.panel', '=', 'ps.panel')->where('sr.subject_type', '=', $subjectType)->whereIn('sr.subject_id', $chunk);
                })
                ->where('ps.panel', $panel)
                ->get(['ps.panel', 'ps.version', 'ps.incarnation', 'ps.updated_at', 'ps.epoch', 'sr.subject_id', 'sr.revision']);
            foreach ($rows as $row) {
                $state ??= PanelState::fromRow($row);

                if ($row->subject_id !== null) {
                    $revisions[Narrow::string($row->subject_id, 'subject_revisions.subject_id')] = Narrow::int($row->revision, 'subject_revisions.revision');
                }
            }
        }

        return [$state, $revisions];
    }

    /** The snapshot transaction on the pinned PDO is still the one this session opened. */
    private function assertOwnSnapshot(): void
    {
        if (! $this->pdo->inTransaction() || $this->storage->connection()->transactionLevel() > 0) {
            throw InvalidConfigurationException::failing('authority_transaction', 'The snapshot read was ended or joined by another transaction.');
        }
    }

    private function assertNoTransaction(?PDO $readPdo = null): void
    {
        if ($this->inSnapshot) {
            $this->assertOwnSnapshot();

            return;
        }
        $connection = $this->storage->connection();
        $write = $connection->getRawPdo();

        if ($this->transaction !== null) {
            $this->transaction->assertActive();

            if ($this->storage->authorityTransaction() !== $this->transaction || ($readPdo !== null && $readPdo !== $write)) {
                throw InvalidConfigurationException::failing('authority_transaction', 'Tentative authority must use the registered root write handle.');
            }

            return;
        }

        if ($this->baselineIdentity !== null) {
            if ($this->baseline?->identity($this->storage) !== $this->baselineIdentity || ($readPdo !== null && $readPdo !== $write)) {
                throw InvalidConfigurationException::failing('authority_transaction', 'The isolated authority baseline or its write handle has changed.');
            }

            return;
        }

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
                $found = is_string($row->schema) ? json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR) : null;
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
