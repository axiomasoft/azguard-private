<?php

declare(strict_types=1);

namespace AzGuard\Storage\Concerns;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Storage\Schema\HostKeyColumns;
use AzGuard\Storage\Storage;

trait BelongsToStorage
{
    private ?Storage $boundStorage = null;

    final public function bindToStorage(Storage $storage, string $table): static
    {
        $this->boundStorage = $storage;
        $this->setConnection($storage->connectionName());
        $this->setTable($table);

        return $this;
    }

    final public function storage(): Storage
    {
        return $this->boundStorage ?? throw new StorageMismatchException('Model must be obtained through Storage::model().');
    }

    /** @param array<string, mixed> $attributes */
    public function newInstance($attributes = [], $exists = false): static
    {
        $model = parent::newInstance($attributes, $exists);

        if ($this->boundStorage !== null) {
            $model->bindToStorage($this->boundStorage, $this->getTable());
        }

        return $model;
    }

    final public function panel(): string
    {
        $panel = $this->identityString('panel');
        PermissionGrammar::assertPanelId($panel);

        return $panel;
    }

    final public function tenantRef(): TenantRef
    {
        $type = $this->identityString('tenant_type', true);
        $id = $this->hostId('tenant_id', true);
        $tenant = $type === null && $id === null ? TenantRef::global() : TenantRef::of(
            $type ?? throw new InvalidIdentityException('Missing tenant type.'),
            $id ?? throw new InvalidIdentityException('Missing tenant id.'),
        );

        if ($tenant->key() !== $this->identityString('tenant_key')) {
            throw new InvalidIdentityException('Tenant key does not match its reference.');
        }

        return $tenant;
    }

    /** @return ($nullable is true ? string|null : string) */
    final protected function identityString(string $column, bool $nullable = false): ?string
    {
        $value = $this->getAttributes()[$column] ?? null;

        if ($nullable && $value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidIdentityException('Invalid identity column '.$column.'.');
        }

        return $value;
    }

    /** @return ($nullable is true ? string|null : string) */
    final protected function hostId(string $column, bool $nullable = false): ?string
    {
        $value = $this->getAttributes()[$column] ?? null;

        if ($nullable && $value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidIdentityException('Invalid host key column '.$column.'.');
        }

        return HostKeyColumns::canonical($this->storage()->hostKeys(), $value);
    }

    /** @return array<string, string> */
    final protected function storageCasts(): array
    {
        return ['panel' => 'string', 'tenant_key' => 'string', 'tenant_type' => 'string', 'tenant_id' => 'string',
            'meta' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
