<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Config\Repository;

it('resolves host keys and default storage parameters', function (): void {
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => ['ids' => ['host_keys' => 'uuid'],
        'storages' => ['default' => [], 'own' => ['table_prefix' => '', 'host_keys' => 'bigint']]]]));
    expect($config->hostKeys())->toBe('uuid')->and($config->storages())->toBe([
        'default' => ['connection' => null, 'table_prefix' => 'azg_', 'host_keys' => 'uuid'],
        'own' => ['connection' => null, 'table_prefix' => '', 'host_keys' => 'bigint'],
    ]);
});

it('rejects malformed storage configuration', function (array $config): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => $config])))
        ->toThrow(InvalidConfigurationException::class);
})->with([
    [['ids' => ['host_keys' => 'integer']]], [['ids' => ['unknown' => 1]]],
    [['storages' => ['Upper' => []]]], [['storages' => ['own' => ['table_prefix' => 'bad-']]]],
    [['storages' => ['own' => ['table_prefix' => str_repeat('x', 21)]]]],
    [['storages' => ['own' => ['connection' => '']]]], [['storages' => ['own' => ['connection' => 1]]]],
    [['storages' => ['own' => ['host_keys' => 'bad']]]], [['storages' => ['own' => ['unknown' => 1]]]],
]);
