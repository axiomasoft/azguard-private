<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Sources\SourceManager;
use AzGuard\Tests\Fixtures\Sources\LdapSource;
use AzGuard\Tests\Fixtures\Sources\OtherLdapSource;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

beforeEach(function (): void {
    LdapSource::$made = [];
});

it('returns the parameters of a named source and an empty array otherwise', function (): void {
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => [
        'sources' => ['ldap' => ['group_attribute' => 'memberOf']],
    ]]));

    expect($config->source('ldap'))->toBe(['group_attribute' => 'memberOf'])
        ->and($config->source('missing'))->toBe([]);
});

it('rejects source parameters that are not an array', function (): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['sources' => ['ldap' => 'memberOf']]])))
        ->toThrow(InvalidConfigurationException::class, 'azguard.sources.ldap');
});

it('rejects source parameters that are a list instead of named values', function (): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['sources' => ['ldap' => ['memberOf']]]])))
        ->toThrow(InvalidConfigurationException::class, 'azguard.sources.ldap must be keyed by parameter name, got int.');
});

it('builds a new object on every make and reads the source configuration', function (): void {
    $config = app('config');
    $config->set('azguard.sources', ['ldap' => ['group_attribute' => 'memberOf']]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->singleton(AzGuardConfig::class, static fn (): AzGuardConfig => AzGuardConfig::fromRepository(app('config')));

    $manager = new SourceManager(app());
    $manager->register(LdapSource::class);

    $admin = $manager->make('ldap', 'admin');
    $cabinet = $manager->make('ldap', 'cabinet');

    expect($admin)->not->toBe($cabinet)
        ->and($admin)->toBeInstanceOf(LdapSource::class)
        ->and($admin->config())->toBe(['group_attribute' => 'memberOf'])
        ->and($manager->getDrivers())->toBe([]);
});

it('ignores a second registration of the same class and rejects another class or extend()', function (): void {
    $manager = new SourceManager(app());
    $manager->register(LdapSource::class);
    $manager->register(LdapSource::class);

    expect(fn () => $manager->register(OtherLdapSource::class))->toThrow(DefinitionException::class, '"ldap"')
        ->and(fn () => $manager->extend('ldap', static fn (Application $app, array $config): LdapSource => new LdapSource($config)))
        ->toThrow(DefinitionException::class, '"ldap"');
});

it('rejects extend() when the name is already extended', function (): void {
    $manager = new SourceManager(app());
    $extension = static fn (Application $app, array $config): LdapSource => new LdapSource($config);
    $manager->extend('ldap', $extension);

    expect(fn () => $manager->extend('ldap', $extension))->toThrow(DefinitionException::class, '"ldap"')
        ->and(fn () => $manager->register(LdapSource::class))->toThrow(DefinitionException::class, '"ldap"');
});

it('has no default source and reports an unknown name', function (): void {
    $manager = app(SourceManager::class);

    expect(fn () => $manager->getDefaultDriver())->toThrow(UnknownSourceException::class, 'no default source')
        ->and(fn () => $manager->make('nope', 'admin'))->toThrow(UnknownSourceException::class, '"nope"');
});
