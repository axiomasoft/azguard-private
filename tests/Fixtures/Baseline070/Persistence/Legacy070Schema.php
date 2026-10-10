<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * The DDL of an AzGuard 0.7.0 storage, frozen on the test side (I16): a copy of `AzGuard\Storage\Schema\StorageSchema`
 * and `HostKeyColumns` at the baseline commit `09612160c98ca6133c894a8f90f5653b9dac6b8f`. Restoring a populated 0.7.0
 * fixture builds its tables here, never through production schema code, because P3 changes that code.
 *
 * `PopulatedDatabaseTest` proves per driver that `create()` equals the production 0.7.0 DDL. That comparison is the
 * only place allowed to change later (P3 turns it into a six-table comparison); this class does not change.
 */
final readonly class Legacy070Schema
{
    /** The seven tables of a 0.7.0 storage, in the order `StorageSchema::drop()` drops them. */
    public const array TABLES = ['audit_log', 'permission_grants', 'role_grants', 'permissions', 'subject_revisions', 'panel_state', 'storage_state'];

    public function __construct(private Connection $connection, private string $storageId, private string $prefix, private string $hostKeys = 'string') {}

    /** The `storage_state.schema` document of the storage at schema `$version`. */
    public function descriptor(int $version = 2): array
    {
        return ['version' => $version, 'identity_codec' => 1, 'storage_id' => $this->storageId, 'prefix' => $this->prefix, 'host_keys' => $this->hostKeys];
    }

    /** `StorageSchema::create()` of 0.7.0: the seven tables at schema 2. */
    public function create(): void
    {
        $this->createTables(2);
    }

    /** A storage as schema 1 created it: no `subject_revisions`, no `panel_state.epoch`, `storage_state` version 1. */
    public function createSchema1(): void
    {
        $this->createTables(1);
    }

    /** `StorageSchema::upgrade()` of 0.7.0: schema 1 to schema 2 in place; a storage at schema 2 is left as it is. */
    public function upgrade(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $row = $this->connection->table($this->prefix.'storage_state')->where('id', 1)->first();
        $stored = $row === null || ! is_string($row->schema) ? null : json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR);
        $expected = $this->descriptor();

        if (! is_array($stored) || ($stored['version'] ?? null) === $expected['version']) {
            return;
        }

        if (! $schema->hasColumn($this->prefix.'panel_state', 'epoch')) {
            $schema->table($this->prefix.'panel_state', static function (Blueprint $table): void {
                $table->bigInteger('epoch')->default(0);
            });
        }

        if (! $schema->hasTable($this->prefix.'subject_revisions')) {
            $this->subjectRevisions();
        }

        if (array_diff_key($stored, ['version' => true]) == array_diff_key($expected, ['version' => true])) {
            $this->connection->table($this->prefix.'storage_state')->where('id', 1)->update(['schema' => json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function drop(): void
    {
        foreach (self::TABLES as $base) {
            $this->connection->getSchemaBuilder()->dropIfExists($this->prefix.$base);
        }
    }

    private function createTables(int $version): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $prefix = $this->prefix;
        $schema->create($prefix.'permissions', function (Blueprint $table) use ($prefix): void {
            $table->bigIncrements('id');
            $this->identifier($table, 'panel', 64);
            $this->scopeColumns($table, 'tenant');
            $this->identifier($table, 'name', 255);
            $table->string('label', 191)->nullable();
            $table->string('group', 191)->nullable();
            $table->text('description')->nullable();
            $this->metadataColumns($table);
            $table->unique(['panel', 'tenant_key', 'name'], $prefix.'pm_identity');
        });
        $this->scopeConstraints('permissions', 'pm', 'tenant');
        foreach (['role' => 'rg', 'permission' => 'pg'] as $identity => $short) {
            $base = $identity.'_grants';
            $schema->create($prefix.$base, function (Blueprint $table) use ($prefix, $identity, $short): void {
                $table->bigIncrements('id');
                $this->identifier($table, 'panel', 64);
                $this->scopeColumns($table, 'tenant');
                $this->identifier($table, $identity, $identity === 'role' ? 64 : 255);
                $this->identifier($table, 'subject_type', 128);
                $this->hostKey($table, 'subject_id');
                $this->scopeColumns($table, 'context');
                $this->identifier($table, 'origin', 128)->default('manual');
                $table->dateTime('expires_at')->nullable();
                $this->identifier($table, 'actor_type', 128)->nullable();
                $this->hostKey($table, 'actor_id')->nullable();
                $table->text('actor_reason')->nullable();
                $this->metadataColumns($table);
                $table->unique(['panel', 'tenant_key', $identity, 'subject_type', 'subject_id', 'context_key', 'origin'], $prefix.$short.'_identity');
                $table->index(['panel', 'tenant_key', 'subject_type', 'subject_id', 'context_key'], $prefix.$short.'_subject');
                $table->index(['panel', 'tenant_key', 'context_type', 'context_id'], $prefix.$short.'_context');
                $table->index(['panel', 'tenant_key', 'origin'], $prefix.$short.'_origin');
                $table->index('expires_at', $prefix.$short.'_expiry');
            });
            $this->scopeConstraints($base, $short, 'tenant');
            $this->scopeConstraints($base, $short, 'context');
        }
        $schema->create($prefix.'audit_log', function (Blueprint $table) use ($prefix): void {
            $table->bigIncrements('id');
            $this->identifier($table, 'event_id', 26);
            $this->identifier($table, 'type', 64);
            $this->identifier($table, 'panel', 64);
            $this->scopeColumns($table, 'tenant');
            $this->identifier($table, 'subject_type', 128)->nullable();
            $this->hostKey($table, 'subject_id')->nullable();
            $this->identifier($table, 'actor_type', 128)->nullable();
            $this->hostKey($table, 'actor_id')->nullable();
            $table->text('actor_reason')->nullable();
            $this->identifier($table, 'correlation_id', 26);
            $table->json('payload');
            $table->dateTime('occurred_at');
            $table->unique('event_id', $prefix.'al_event');
            $table->index(['panel', 'occurred_at'], $prefix.'al_time');
            $table->index(['panel', 'tenant_key', 'subject_type', 'subject_id'], $prefix.'al_subject');
            $table->index('correlation_id', $prefix.'al_corr');
        });
        $this->scopeConstraints('audit_log', 'al', 'tenant');
        $schema->create($prefix.'panel_state', function (Blueprint $table) use ($prefix, $version): void {
            $this->identifier($table, 'panel', 64);
            $table->primary('panel', $prefix.'ps_pk');
            $table->bigInteger('version')->default(0);
            $this->identifier($table, 'incarnation', 26);
            $table->dateTime('updated_at');

            if ($version >= 2) {
                $table->bigInteger('epoch')->default(0);
            }
        });

        if ($version >= 2) {
            $this->subjectRevisions();
        }
        $schema->create($prefix.'storage_state', function (Blueprint $table) use ($prefix): void {
            $table->smallInteger('id');
            $table->primary('id', $prefix.'ss_pk');
            $table->json('schema');
        });
        $this->constraint('storage_state', 'ss_singleton', 'id = 1', ['id']);
        $this->connection->table($prefix.'storage_state')->insert(['id' => 1, 'schema' => json_encode($this->descriptor($version), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }

    private function subjectRevisions(): void
    {
        $prefix = $this->prefix;
        $this->connection->getSchemaBuilder()->create($prefix.'subject_revisions', function (Blueprint $table) use ($prefix): void {
            $this->identifier($table, 'panel', 64);
            $this->identifier($table, 'subject_type', 128);
            $this->hostKey($table, 'subject_id');
            $table->bigInteger('revision')->default(0);
            $table->primary(['panel', 'subject_type', 'subject_id'], $prefix.'sr_pk');
        });
    }

    private function scopeColumns(Blueprint $table, string $scope): void
    {
        $this->identifier($table, $scope.'_key', 200);
        $this->identifier($table, $scope.'_type', 128)->nullable();
        $this->hostKey($table, $scope.'_id')->nullable();
    }

    private function metadataColumns(Blueprint $table): void
    {
        $table->json('meta')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    }

    private function scopeConstraints(string $base, string $short, string $scope): void
    {
        $key = $scope.'_key';
        $type = $scope.'_type';
        $id = $scope.'_id';
        $this->constraint($base, $short.'_'.$scope.'_pair',
            "(($key = 'global') = ($type IS NULL AND $id IS NULL)) AND (($type IS NULL) = ($id IS NULL))", [$key, $type, $id]);
    }

    /** @param list<string> $columns */
    private function constraint(string $base, string $name, string $predicate, array $columns): void
    {
        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->prefix.$base);

        if ($this->connection->getDriverName() !== 'sqlite') {
            $name = $grammar->wrap($this->prefix.$name);
            $this->connection->statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($predicate)");

            return;
        }
        $newPredicate = $predicate;
        foreach ($columns as $column) {
            $newPredicate = str_replace($column, 'NEW.'.$column, $newPredicate);
        }
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF '.implode(', ', $columns)] as $suffix => $operation) {
            $trigger = $grammar->wrap($this->prefix.$name.'_'.$suffix);
            $this->connection->statement("CREATE TRIGGER $trigger BEFORE $operation ON $table WHEN NOT ($newPredicate) BEGIN SELECT RAISE(ABORT, 'azguard: $name'); END");
        }
    }

    /** `HostKeyColumns::identifier()` of 0.7.0. */
    private function identifier(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $driver = $this->connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $table->binary($name, $length);
        }
        $column = $table->string($name, $length);

        if ($driver === 'pgsql') {
            $column->collation('C');
        }

        return $column;
    }

    /** `HostKeyColumns::hostKey()` of 0.7.0. */
    private function hostKey(Blueprint $table, string $name): ColumnDefinition
    {
        $driver = $this->connection->getDriverName();

        return match ($this->hostKeys) {
            'bigint' => $table->bigInteger($name),
            'uuid' => in_array($driver, ['mysql', 'mariadb'], true)
                ? $table->binary($name, 36) : $table->uuid($name),
            'ulid' => in_array($driver, ['mysql', 'mariadb'], true)
                ? $table->binary($name, 26) : $table->char($name, 26)->collation($driver === 'pgsql' ? 'C' : 'BINARY'),
            default => $this->identifier($table, $name, 64),
        };
    }
}
