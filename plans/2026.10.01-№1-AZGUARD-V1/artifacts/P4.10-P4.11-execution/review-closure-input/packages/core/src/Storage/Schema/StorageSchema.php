<?php

declare(strict_types=1);

namespace AzGuard\Storage\Schema;

use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Database\Schema\Blueprint;

/**
 * Creates and drops the complete schema of a configured AzGuard storage.
 *
 * @api
 */
final readonly class StorageSchema
{
    public function __construct(private StorageRegistry $storages) {}

    public function create(string $storage): void
    {
        $storage = $this->storages->get($storage);
        $schema = $storage->connection()->getSchemaBuilder();
        $prefix = $storage->prefix();
        $driver = $storage->connection()->getDriverName();
        $schema->create($prefix.'permissions', function (Blueprint $table) use ($storage, $prefix, $driver): void {
            $table->bigIncrements('id');
            HostKeyColumns::identifier($table, 'panel', 64, $driver);
            $this->scopeColumns($table, $storage, 'tenant');
            HostKeyColumns::identifier($table, 'name', 255, $driver);
            $table->string('label', 191)->nullable();
            $table->string('group', 191)->nullable();
            $table->text('description')->nullable();
            $this->metadataColumns($table);
            $table->unique(['panel', 'tenant_key', 'name'], $prefix.'pm_identity');
        });
        $this->scopeConstraints($storage, 'permissions', 'pm', 'tenant');
        foreach (['role' => 'rg', 'permission' => 'pg'] as $identity => $short) {
            $base = $identity.'_grants';
            $schema->create($prefix.$base, function (Blueprint $table) use ($storage, $prefix, $driver, $identity, $short): void {
                $table->bigIncrements('id');
                HostKeyColumns::identifier($table, 'panel', 64, $driver);
                $this->scopeColumns($table, $storage, 'tenant');
                HostKeyColumns::identifier($table, $identity, $identity === 'role' ? 64 : 255, $driver);
                HostKeyColumns::identifier($table, 'subject_type', 128, $driver);
                HostKeyColumns::hostKey($table, 'subject_id', $storage->hostKeys(), $driver);
                $this->scopeColumns($table, $storage, 'context');
                HostKeyColumns::identifier($table, 'origin', 128, $driver)->default('manual');
                $table->dateTime('expires_at')->nullable();
                HostKeyColumns::identifier($table, 'actor_type', 128, $driver)->nullable();
                HostKeyColumns::hostKey($table, 'actor_id', $storage->hostKeys(), $driver)->nullable();
                $table->text('actor_reason')->nullable();
                $this->metadataColumns($table);
                $table->unique(['panel', 'tenant_key', $identity, 'subject_type', 'subject_id', 'context_key', 'origin'], $prefix.$short.'_identity');
                $table->index(['panel', 'tenant_key', 'subject_type', 'subject_id', 'context_key'], $prefix.$short.'_subject');
                $table->index(['panel', 'tenant_key', 'context_type', 'context_id'], $prefix.$short.'_context');
                $table->index(['panel', 'tenant_key', 'origin'], $prefix.$short.'_origin');
                $table->index('expires_at', $prefix.$short.'_expiry');
            });
            $this->scopeConstraints($storage, $base, $short, 'tenant');
            $this->scopeConstraints($storage, $base, $short, 'context');
        }
        $schema->create($prefix.'panel_state', function (Blueprint $table) use ($prefix, $driver): void {
            HostKeyColumns::identifier($table, 'panel', 64, $driver);
            $table->primary('panel', $prefix.'ps_pk');
            $table->bigInteger('version')->default(0);
            HostKeyColumns::identifier($table, 'incarnation', 26, $driver);
            $table->dateTime('updated_at');
        });
        $schema->create($prefix.'storage_state', function (Blueprint $table) use ($prefix): void {
            $table->smallInteger('id');
            $table->primary('id', $prefix.'ss_pk');
            $table->json('schema');
        });
        $this->constraint($storage, 'storage_state', 'ss_singleton', 'id = 1', ['id']);
        $storage->table('storage_state')->insert(['id' => 1, 'schema' => json_encode($storage->schema(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }

    public function drop(string $storage): void
    {
        $storage = $this->storages->get($storage);
        foreach (['permission_grants', 'role_grants', 'permissions', 'panel_state', 'storage_state'] as $base) {
            $storage->connection()->getSchemaBuilder()->dropIfExists($storage->prefix().$base);
        }
    }

    private function scopeColumns(Blueprint $table, Storage $storage, string $scope): void
    {
        $driver = $storage->connection()->getDriverName();
        HostKeyColumns::identifier($table, $scope.'_key', 200, $driver);
        HostKeyColumns::identifier($table, $scope.'_type', 128, $driver)->nullable();
        HostKeyColumns::hostKey($table, $scope.'_id', $storage->hostKeys(), $driver)->nullable();
    }

    private function metadataColumns(Blueprint $table): void
    {
        $table->json('meta')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    }

    private function scopeConstraints(Storage $storage, string $base, string $short, string $scope): void
    {
        $key = $scope.'_key';
        $type = $scope.'_type';
        $id = $scope.'_id';
        $this->constraint($storage, $base, $short.'_'.$scope.'_pair',
            "(($key = 'global') = ($type IS NULL AND $id IS NULL)) AND (($type IS NULL) = ($id IS NULL))", [$key, $type, $id]);
    }

    /** @param list<string> $columns */
    private function constraint(Storage $storage, string $base, string $name, string $predicate, array $columns): void
    {
        $connection = $storage->connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($storage->prefix().$base);

        if ($connection->getDriverName() !== 'sqlite') {
            $name = $grammar->wrap($storage->prefix().$name);
            $connection->statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($predicate)");

            return;
        }
        $newPredicate = $predicate;
        foreach ($columns as $column) {
            $newPredicate = str_replace($column, 'NEW.'.$column, $newPredicate);
        }
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF '.implode(', ', $columns)] as $suffix => $operation) {
            $trigger = $grammar->wrap($storage->prefix().$name.'_'.$suffix);
            $connection->statement("CREATE TRIGGER $trigger BEFORE $operation ON $table WHEN NOT ($newPredicate) BEGIN SELECT RAISE(ABORT, 'azguard: $name'); END");
        }
    }
}
