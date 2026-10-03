<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Database\DatabaseManager;

final class StorageRegistry
{
    /** @var array<string, Storage> */
    private array $storages = [];

    public function __construct(AzGuardConfig $config, DatabaseManager $database)
    {
        foreach ($config->storages() as $id => $parameters) {
            $this->register(new Storage($id, $database->connection($parameters['connection']), $parameters['table_prefix'], $parameters['host_keys']));
        }
    }

    public function get(string $name): Storage
    {
        return $this->storages[$name] ?? throw InvalidConfigurationException::failing('storage', 'Unknown storage '.$name.'.');
    }

    public function register(Storage $storage): void
    {
        foreach ($this->storages as $existing) {
            if ($existing->id() === $storage->id()
                || ($existing->connectionName() === $storage->connectionName() && $existing->prefix() === $storage->prefix())) {
                throw InvalidConfigurationException::failing('storage', 'Storage name or connection/prefix is already registered.');
            }
        }
        $this->storages[$storage->id()] = $storage;
    }
}
