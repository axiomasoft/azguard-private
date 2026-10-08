<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Config\Repository;

it('provides a singleton registry and rejects aliases of physical storage', function (): void {
    $registry = app(StorageRegistry::class);
    expect(app(StorageRegistry::class))->toBe($registry)->and($registry->get('default')->connectionName())->toBe('testbench');
    expect(fn () => $registry->get('missing'))->toThrow(InvalidConfigurationException::class);
    expect(fn () => $registry->register(new Storage('alias', $registry->get('default')->connection(), 'azg_')))
        ->toThrow(StorageMismatchException::class);
    $registry->register(new Storage('own', $registry->get('default')->connection(), 'adm_', 'uuid'));
    expect($registry->get('own')->hostKeys())->toBe('uuid');
    expect(fn () => $registry->register(new Storage('own', $registry->get('default')->connection(), 'other_')))
        ->toThrow(StorageMismatchException::class);
});

it('resolves null connection before checking duplicate configuration', function (): void {
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => ['storages' => ['first' => [],
        'second' => ['connection' => 'testbench']]]]));
    expect(fn () => new StorageRegistry($config, app('db')))->toThrow(StorageMismatchException::class);
});
