<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $azguard
 */
function azguardConfig(array $azguard): AzGuardConfig
{
    return AzGuardConfig::fromRepository(new Repository(['azguard' => $azguard]));
}

it('reads the defaults of the published configuration file', function (): void {
    $config = azguardConfig(require dirname(__DIR__, 3).'/packages/core/config/azguard.php');

    expect($config->panelProviders())->toBe([])
        ->and($config->defaults())->toBe([
            'resource_prefix' => true,
            'trace_decisions' => false,
            'gate.mode' => 'authoritative',
            'cache.store' => null,
            'cache.ttl' => 3600,
            'cache.generation' => 1,
            'consistency.reads' => 'primary',
            'consistency.state_refresh' => 'request',
        ]);
});

it('returns only the defaults the configuration sets', function (): void {
    expect(azguardConfig([])->defaults())->toBe([])
        ->and(azguardConfig(['defaults' => ['cache' => ['ttl' => 60]]])->defaults())->toBe(['cache.ttl' => 60])
        ->and(azguardConfig(['defaults' => ['resource_prefix' => false, 'cache' => ['store' => 'redis', 'ttl' => null]]])->defaults())
        ->toBe(['resource_prefix' => false, 'cache.store' => 'redis', 'cache.ttl' => null]);
});

it('takes integers that arrive from the environment as strings', function (): void {
    expect(azguardConfig(['defaults' => ['cache' => ['ttl' => '900', 'generation' => '3']]])->defaults())
        ->toBe(['cache.ttl' => 900, 'cache.generation' => 3]);
});

it('keeps enumerated values as strings for the panel compiler to check', function (): void {
    expect(azguardConfig(['defaults' => ['gate' => ['mode' => 'permissive'], 'consistency' => ['reads' => 'replica']]])->defaults())
        ->toBe(['gate.mode' => 'permissive', 'consistency.reads' => 'replica']);
});

it('rejects a key it does not know', function (array $azguard, string $message): void {
    expect(fn () => azguardConfig($azguard))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'in defaults' => [['defaults' => ['strict_writes' => false]], 'Unknown key in azguard.defaults: strict_writes'],
    'a switch for a guarantee' => [['defaults' => ['fail_closed' => false, 'restrictions' => false]], 'Unknown keys in azguard.defaults: fail_closed, restrictions'],
    'in defaults.gate' => [['defaults' => ['gate' => ['enabled' => false]]], 'Unknown key in azguard.defaults.gate: enabled'],
    'in defaults.cache' => [['defaults' => ['cache' => ['driver' => 'redis']]], 'Unknown key in azguard.defaults.cache: driver'],
    'in defaults.consistency' => [['defaults' => ['consistency' => ['writes' => 'primary']]], 'Unknown key in azguard.defaults.consistency: writes'],
    'in panels' => [['panels' => ['provider' => []]], 'Unknown key in azguard.panels: provider'],
]);

it('rejects a value of the wrong type', function (array $defaults, string $code, string $message): void {
    try {
        azguardConfig(['defaults' => $defaults]);
        $this->fail('The configuration was accepted.');
    } catch (InvalidConfigurationException $e) {
        expect($e->code())->toBe($code)
            ->and($e->getMessage())->toContain($message);
    }
})->with([
    'resource_prefix is a string' => [['resource_prefix' => 'backoffice'], 'invalid_configuration', 'azguard.defaults.resource_prefix must be true or false'],
    'trace_decisions is a number' => [['trace_decisions' => 1], 'invalid_configuration', 'azguard.defaults.trace_decisions'],
    'a group is not an array' => [['cache' => 'redis'], 'invalid_configuration', 'azguard.defaults.cache must be an array'],
    'cache.store is empty' => [['cache' => ['store' => '']], 'invalid_configuration', 'azguard.defaults.cache.store'],
    'cache.ttl is not a number' => [['cache' => ['ttl' => 'hour']], 'invalid_configuration.enum', 'azguard.defaults.cache.ttl must be an integer or null'],
    'cache.generation is null' => [['cache' => ['generation' => null]], 'invalid_configuration.enum', 'azguard.defaults.cache.generation must be an integer,'],
    'gate.mode is not a string' => [['gate' => ['mode' => true]], 'invalid_configuration.enum', 'azguard.defaults.gate.mode must be a string'],
]);

it('rejects sections that are not arrays', function (): void {
    expect(fn () => azguardConfig(['defaults' => 'none']))->toThrow(InvalidConfigurationException::class, 'azguard.defaults must be an array')
        ->and(fn () => azguardConfig(['panels' => null]))->toThrow(InvalidConfigurationException::class, 'azguard.panels must be an array');
});

it('names the failed check in the code and keeps the base code otherwise', function (): void {
    expect((new InvalidConfigurationException('m'))->code())->toBe('invalid_configuration')
        ->and(InvalidConfigurationException::failing('cache_ttl', 'm')->code())->toBe('invalid_configuration.cache_ttl')
        ->and(InvalidConfigurationException::failing('enum', 'm')->getMessage())->toBe('m');
});
