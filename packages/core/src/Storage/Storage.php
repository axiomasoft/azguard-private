<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Reads;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Attributes\Connection as ModelConnection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use PDO;
use ReflectionClass;
use stdClass;
use Throwable;

use function Illuminate\Support\enum_value;

/**
 * A configured physical storage or a registry-managed private storage.
 *
 * @api
 */
final class Storage
{
    /** 2: subject revisions and the panel epoch (audits/2026-10-09-consistency-design.md, step 3). */
    public const int SCHEMA_VERSION = 2;

    use DetectsConcurrencyErrors;

    /** @var array<string, PanelState> */
    private array $locked = [];

    private ?StorageMutation $current = null;

    private ?AuthorityTransaction $authorityTransaction = null;

    private bool $schemaChecked = false;

    public function __construct(private readonly string $id, private readonly Connection $connection,
        private readonly string $prefix = 'azg_', private readonly string $hostKeys = 'string')
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1
            || preg_match('/\A([a-z][a-z0-9_]{0,19})?\z/', $prefix) !== 1) {
            throw InvalidConfigurationException::failing('storage', 'Invalid storage identity or prefix.');
        }

        if (! in_array($hostKeys, ['string', 'bigint', 'uuid', 'ulid'], true)) {
            throw InvalidConfigurationException::failing('host_keys', 'Invalid host key type.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public static function own(?string $connection = null, string $prefix = 'azg_', string $hostKeys = 'string'): self
    {
        return app(StorageRegistry::class)->own($connection, $prefix, $hostKeys);
    }

    /** @internal Consumed authority reads share one PDO for their complete fence. */
    public function readSession(Reads $reads): StorageReadSession
    {
        return new StorageReadSession($this, $reads, fn (string $kind, ?string $class): Model => $this->makeModel($kind, $class));
    }

    /** @internal Only the storage's currently registered capability can authorize tentative reads. */
    public function authorityTransaction(?string $panel = null): ?AuthorityTransaction
    {
        $this->authorityTransaction?->assertActive();

        if ($this->authorityTransaction !== null && $panel !== null && ! isset($this->locked[$panel])) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Panel state must be locked by the authority root before reading.');
        }

        return $this->authorityTransaction;
    }

    /** @internal Start a joint host/authority root, locking state before the host callback.
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function withinAuthorityTransaction(string $panel, Closure $work): mixed
    {
        $pdo = $this->connection->getRawPdo();

        if ($this->connection->transactionLevel() !== 0 || ($pdo instanceof PDO && $pdo->inTransaction())) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Joint authority work requires a new root transaction.');
        }

        return $this->mutate($panel, static fn (): mixed => $work());
    }

    /**
     * @internal The active mutation that holds the panel lock; a write outside one is refused.
     *
     * @throws UnsupportedDirectWriteException
     */
    public function mutation(string $panel): StorageMutation
    {
        PermissionGrammar::assertPanelId($panel);
        $current = $this->current;

        if ($current === null || ! $current->holds($panel)) {
            throw new UnsupportedDirectWriteException('Panel '.$panel.' is written only inside its active storage mutation.');
        }

        return $current;
    }

    public function connectionName(): string
    {
        return $this->connection->getName() ?? throw InvalidConfigurationException::failing('storage', 'Storage requires a named connection.');
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hostKeys(): string
    {
        return $this->hostKeys;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function table(string $base): Builder
    {
        return $this->connection->table($this->prefix.$base)->useWritePdo();
    }

    public function model(string $kind, ?string $class = null): Model
    {
        $model = $this->makeModel($kind, $class);
        $this->assertSchema();

        return $model;
    }

    /**
     * Checks that the model class fits its kind and this storage, without reading the database.
     *
     * @internal
     *
     * @throws StorageMismatchException
     */
    public function assertModel(string $kind, ?string $class = null): void
    {
        $this->makeModel($kind, $class);
    }

    private function makeModel(string $kind, ?string $class): Model
    {
        [$base, $table] = match ($kind) {
            'role_grant' => [RoleGrant::class, 'role_grants'],
            'permission_grant' => [PermissionGrant::class, 'permission_grants'],
            'permission' => [Permission::class, 'permissions'],
            default => throw new StorageMismatchException('Unknown storage model kind '.$kind.'.'),
        };
        $class ??= app(AzGuardConfig::class)->defaultModels()[$kind];

        if (! is_a($class, $base, true)) {
            throw new StorageMismatchException('Storage model '.$class.' must extend '.$base.'.');
        }
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new StorageMismatchException('Storage model '.$class.' must be concrete.');
        }
        $expectedTable = $this->prefix.$table;
        $defaults = $reflection->getDefaultProperties();
        $this->assertModelSetting($class, 'table', $defaults['table'] ?? null, $expectedTable);
        $this->assertModelSetting($class, 'connection', $defaults['connection'] ?? null, $this->connectionName());

        do {
            $this->assertModelAttributes($class, $reflection, $expectedTable);
            foreach ($reflection->getTraits() as $trait) {
                $this->assertModelAttributes($class, $trait, $expectedTable);
            }
        } while (($reflection = $reflection->getParentClass()) !== false);

        return (new $class)->bindToStorage($this, $expectedTable);
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertModelAttributes(string $class, ReflectionClass $reflection, string $table): void
    {
        foreach ([Table::class => ['table', $table], ModelConnection::class => ['connection', $this->connectionName()]] as $attribute => [$setting, $expected]) {
            if (! class_exists($attribute)) {
                continue;
            }
            foreach ($reflection->getAttributes($attribute) as $declaration) {
                $this->assertModelSetting($class, $setting, $declaration->newInstance()->name, $expected);
            }
        }
    }

    private function assertModelSetting(string $class, string $setting, mixed $declared, string $expected): void
    {
        if ($declared !== null && enum_value($declared) !== $expected) {
            throw new StorageMismatchException('Storage model '.$class.' declares '.$setting.' '.json_encode(enum_value($declared)).'; expected '.$expected.'.');
        }
    }

    public function state(string $panel): ?PanelState
    {
        PermissionGrammar::assertPanelId($panel);
        $this->assertSchema();
        $row = $this->table('panel_state')->where('panel', $panel)->first();

        return $row === null ? null : $this->panelState($row);
    }

    /**
     * @template T
     *
     * @param  string|list<string>  $panels
     * @param  Closure(StorageMutation): T  $work
     * @return T
     */
    public function mutate(string|array $panels, Closure $work, int $attempts = 3): mixed
    {
        $panels = is_string($panels) ? [$panels] : $panels;
        foreach ($panels as $panel) {
            PermissionGrammar::assertPanelId($panel);
        }
        $panels = array_values(array_unique($panels));
        sort($panels, SORT_STRING);

        if ($attempts < 1 || $panels === []) {
            throw new InvalidArgumentException('Mutation needs panels and at least one attempt.');
        }
        $this->assertSchema();
        $nested = $this->connection->transactionLevel() > 0;
        for ($attempt = 1; ; $attempt++) {
            $committed = false;

            try {
                return $this->connection->transaction(function () use ($panels, $work, $nested, &$committed): mixed {
                    $this->connection->afterCommit(static function () use (&$committed): void {
                        $committed = true;
                    });

                    return $this->perform($panels, $work, $nested);
                }, 1);
            } catch (Throwable $error) {
                if ($committed || $nested || $attempt >= $attempts || ! $this->causedByConcurrencyError($error)) {
                    throw $error;
                }
                usleep(random_int(5, 25) * $attempt * 1000);
            }
        }
    }

    /** @template T
     * @param  list<string>  $panels
     * @param  Closure(StorageMutation): T  $work
     * @return T
     */
    private function perform(array $panels, Closure $work, bool $nested): mixed
    {
        $parent = $this->current;
        $previousLocks = $this->locked;
        $previousTouches = $this->pendingTouches;
        $previousEpochs = $this->pendingEpochs;
        $previousSubjects = $this->pendingSubjects;
        $previousTransaction = $this->authorityTransaction;
        $mutation = null;

        try {
            if (! $nested) {
                $this->authorityTransaction = AuthorityTransaction::begin($this);
            }
            $this->authorityTransaction?->assertActive();
            $states = [];
            foreach ($panels as $panel) {
                $states[$panel] = $this->locked[$panel] ??= $this->lockPanel($panel);
            }
            $mutation = new StorageMutation($this, $states, $nested);
            $this->current = $mutation;
            $result = $work($mutation);
            $this->authorityTransaction?->assertActive();
            foreach ($mutation->touched() as $panel) {
                $this->pendingTouches[$panel] = true;

                if ($mutation->touchesEpoch($panel)) {
                    $this->pendingEpochs[$panel] = true;
                }
                $this->pendingSubjects[$panel] = ($this->pendingSubjects[$panel] ?? []) + $mutation->touchedSubjects($panel);

                if ($parent?->holds($panel)) {
                    $parent->absorb($panel, $mutation->touchesEpoch($panel), $mutation->touchedSubjects($panel));
                }
            }

            if ($parent === null) {
                $touched = array_keys($this->pendingTouches);
                foreach ($touched as $panel) {
                    $this->commitTouch($panel);
                }
            }

            if ($parent === null && $touched !== []) {
                $this->connection->afterCommit(static fn () => event(new StorageTouched($touched)));
            }
            foreach ($mutation->callbacks() as $callback) {
                $this->connection->afterCommit($callback);
            }

            return $result;
        } catch (Throwable $error) {
            $this->locked = $previousLocks;
            $this->pendingTouches = $previousTouches;
            $this->pendingEpochs = $previousEpochs;
            $this->pendingSubjects = $previousSubjects;

            throw $error;
        } finally {
            if ($previousTransaction !== $this->authorityTransaction) {
                $this->authorityTransaction?->close();
                $this->authorityTransaction = $previousTransaction;
            }
            $mutation?->close();
            $this->current = $parent;

            if ($parent === null) {
                $this->locked = [];
                $this->pendingTouches = [];
                $this->pendingEpochs = [];
                $this->pendingSubjects = [];
            }
        }
    }

    /** @var array<string, true> */
    private array $pendingTouches = [];

    /** @var array<string, true> */
    private array $pendingEpochs = [];

    /** @var array<string, array<string, array{string, string}>> */
    private array $pendingSubjects = [];

    /**
     * The root commit of a touched panel, under its lock: the version always moves; the epoch moves for a change
     * whose subjects are not named; each named subject's revision moves (a missing revision row is revision 0, rows
     * are never deleted). Subjects are written in a canonical order.
     */
    private function commitTouch(string $panel): void
    {
        $this->table('panel_state')->where('panel', $panel)
            ->incrementEach(isset($this->pendingEpochs[$panel]) ? ['version' => 1, 'epoch' => 1] : ['version' => 1], ['updated_at' => gmdate('Y-m-d H:i:s')]);
        $subjects = $this->pendingSubjects[$panel] ?? [];
        ksort($subjects, SORT_STRING);
        foreach ($subjects as [$type, $id]) {
            $this->table('subject_revisions')->insertOrIgnore(['panel' => $panel, 'subject_type' => $type, 'subject_id' => $id, 'revision' => 0]);
            $this->table('subject_revisions')->where('panel', $panel)->where('subject_type', $type)->where('subject_id', $id)->increment('revision');
        }
    }

    /**
     * @internal A new incarnation of the panel state, the reset after a manual change or a restore of the database.
     *
     * Runs inside the root mutation that holds the lock of `panel_state` — the lock every write of the panel takes —
     * and marks the panel touched, so the root commit also raises its version. Tokens and cached decisions of the old
     * incarnation never pass the fence again. A rollback or retry of the root keeps the old incarnation.
     *
     * @throws UnsupportedDirectWriteException outside the active mutation of the panel
     * @throws InvalidConfigurationException inside a nested mutation: a reset is its own root
     */
    public function renewIncarnation(string $panel): PanelState
    {
        $mutation = $this->mutation($panel);

        if ($mutation->isNested()) {
            throw InvalidConfigurationException::failing('panel_state', 'A reset of panel '.$panel.' runs in its own root mutation, not inside another transaction.');
        }
        $state = $mutation->state($panel);
        $renewed = new PanelState($panel, $state->version, strtolower((string) Str::ulid()), $state->updatedAt, $state->epoch);
        $this->table('panel_state')->where('panel', $panel)->update(['incarnation' => $renewed->incarnation]);
        $this->locked[$panel] = $renewed;
        $mutation->renewed($renewed);
        $mutation->touch($panel);

        return $renewed;
    }

    /** @return array{version: int, identity_codec: int, storage_id: string, prefix: string, host_keys: string} */
    public function schema(): array
    {
        return ['version' => self::SCHEMA_VERSION, 'identity_codec' => IdentityCodec::VERSION,
            'storage_id' => $this->id, 'prefix' => $this->prefix, 'host_keys' => $this->hostKeys];
    }

    private function assertSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->verifySchema();
        $this->schemaChecked = true;
    }

    /**
     * Compares the stored `storage_state` with the configuration of this storage now, whatever an earlier check found.
     *
     * @internal read by the doctor
     *
     * @throws StorageMismatchException when the state differs or cannot be read; a read failure is the previous exception
     */
    public function verifySchema(): void
    {
        $expected = $this->schema();
        $found = null;

        try {
            $row = $this->table('storage_state')->where('id', 1)->first();

            if ($row !== null) {
                $found = is_string($row->schema) ? json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR) : null;
            }
        } catch (QueryException|JsonException $error) {
            throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; cannot read storage_state: '.$error->getMessage(), 0, $error);
        }

        if (! is_array($found) || count($found) !== count($expected)
            || array_filter($expected, static fn (int|string $value, string $key): bool => ($found[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
            $hint = is_array($found) && ($found['version'] ?? null) === 1
                ? ' Schema 1 predates subject revisions and the panel epoch: publish and run the AzGuard schema 2 upgrade migration.' : '';

            throw new StorageMismatchException('Storage '.$this->id.' expected '.json_encode($expected).'; found '.json_encode($found).'.'.$hint);
        }
    }

    private function lockPanel(string $panel): PanelState
    {
        $query = $this->table('panel_state')->where('panel', $panel);
        $row = $this->connection->getDriverName() === 'sqlite' ? null : $query->lockForUpdate()->first();

        if ($row === null) {
            $this->table('panel_state')->insertOrIgnore(['panel' => $panel, 'version' => 0,
                'incarnation' => strtolower((string) Str::ulid()), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $row = $query->lockForUpdate()->firstOrFail();
        }

        return $this->panelState($row);
    }

    private function panelState(stdClass $row): PanelState
    {
        return PanelState::fromRow($row);
    }
}
