<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Storage\StorageDescriptor;
use Illuminate\Database\DatabaseManager;

final class StorageRegistry
{
    /** @var array<string, Storage> */
    private array $storages = [];

    /** @var array<string, string|null> the connection each storage was declared with; null is the default connection */
    private array $connections = [];

    public function __construct(AzGuardConfig $config, private readonly DatabaseManager $database)
    {
        foreach ($config->storages() as $id => $parameters) {
            $this->register(new Storage($id, $database->connection($parameters['connection']), $parameters['table_prefix'], $parameters['host_keys']));
            $this->connections[$id] = $parameters['connection'];
        }
    }

    public function get(string $name): Storage
    {
        return $this->storages[$name] ?? throw InvalidConfigurationException::failing('storage', 'Unknown storage '.$name.'.');
    }

    public function own(?string $connection = null, string $prefix = 'azg_', string $hostKeys = 'string'): Storage
    {
        $resolved = $this->database->connection($connection);
        $name = $resolved->getName() ?? throw InvalidConfigurationException::failing('storage', 'Storage requires a named connection.');
        $id = 'own-'.substr(hash('sha256', IdentityCodec::compose([$name, $prefix])), 0, 60);
        $storage = new Storage($id, $resolved, $prefix, $hostKeys);

        foreach ($this->storages as $existing) {
            if ($existing->connectionName() === $name && $existing->prefix() === $prefix) {
                if ($existing->hostKeys() !== $hostKeys) {
                    throw InvalidConfigurationException::failing('host_keys', 'Storage connection/prefix is already registered with different host keys.');
                }

                return $existing;
            }
        }
        $this->register($storage);
        $this->connections[$storage->id()] = $connection;

        return $storage;
    }

    public function register(Storage $storage): void
    {
        foreach ($this->storages as $existing) {
            if ($existing->id() === $storage->id()
                || ($existing->connectionName() === $storage->connectionName() && $existing->prefix() === $storage->prefix())) {
                throw new StorageMismatchException('Storage '.$storage->id().' names the physical storage of '.$existing->id().' (same connection and table prefix) or repeats its name.');
            }
        }
        $this->storages[$storage->id()] = $storage;
    }

    /** @throws InvalidConfigurationException when no storage has the id */
    public function descriptor(string $id): StorageDescriptor
    {
        $storage = $this->get($id);

        return new StorageDescriptor(
            $storage->id(),
            array_key_exists($id, $this->connections) ? $this->connections[$id] : $storage->connectionName(),
            $storage->prefix(),
            $storage->hostKeys(),
        );
    }

    /** @return list<StorageDescriptor> every storage, in registration order */
    public function descriptors(): array
    {
        return array_map($this->descriptor(...), array_keys($this->storages));
    }
}
