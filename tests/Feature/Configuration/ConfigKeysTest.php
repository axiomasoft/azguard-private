<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Tests\Fixtures\Configuration\BootsWithConfiguration;
use AzGuard\Tests\Fixtures\Panels\FixedScopeResolver;
use AzGuard\Tests\Fixtures\Panels\FixedTenantResolver;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Access\Gate;

uses(BootsWithConfiguration::class);

/**
 * The keys of a configuration array as dotted paths. A list, an empty map and a scalar are leaves, the map of source
 * parameters is one leaf, and the name of a storage is written `*`, as `AzGuardConfig::knownKeys()` writes it.
 *
 * @param  array<mixed>  $config
 * @return list<string>
 */
function configurationKeys(array $config, string $prefix = ''): array
{
    $keys = [];

    foreach ($config as $key => $value) {
        $path = $prefix.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value) && $path !== 'sources') {
            array_push($keys, ...configurationKeys($value, $path.'.'));

            continue;
        }

        $keys[] = (string) preg_replace('/\Astorages\.[^.]+\./', 'storages.*.', $path);
    }

    sort($keys, SORT_STRING);

    return array_values(array_unique($keys));
}

/** @return array<mixed> */
function publishedConfiguration(): array
{
    return require dirname(__DIR__, 3).'/packages/core/config/azguard.php';
}

it('publishes the file with exactly the keys of the snapshot', function (): void {
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Configuration/config-keys.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(configurationKeys(publishedConfiguration()))->toBe($snapshot);
});

it('accepts exactly the keys of the published file', function (): void {
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Configuration/config-keys.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(AzGuardConfig::knownKeys())->toBe($snapshot)
        ->and(AzGuardConfig::fromRepository(new Repository(['azguard' => publishedConfiguration()])))->toBeInstanceOf(AzGuardConfig::class);
});

it('rejects an unknown key in every section of the file', function (string $section): void {
    $config = publishedConfiguration();

    if ($section === '') {
        $config['typo'] = true;
    } else {
        data_set($config, $section.'.typo', true);
    }

    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => $config])))
        ->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard'.($section === '' ? '' : '.'.$section).': typo');
})->with(['', 'panels', 'storages.default', 'ids', 'defaults', 'defaults.models', 'defaults.tenants', 'defaults.scopes', 'defaults.gate', 'defaults.cache',
    'defaults.consistency', 'gate', 'schedule', 'catalog', 'discovery', 'scaffold']);

it('reads the new sections with their defaults', function (): void {
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => []]));

    expect($config->gateEnabled())->toBeTrue()
        ->and($config->scheduleEnabled())->toBeTrue()
        ->and($config->pruneExpiredFrequency())->toBe('daily')
        ->and($config->scaffoldNamespace())->toBe('App\\Guards')
        ->and($config->scaffoldPath())->toBe('app/Guards')
        ->and($config->defaultTenantResolvers())->toBe([])
        ->and($config->defaultScopeResolvers())->toBe([]);
});

it('normalizes the generator target and the prune frequency', function (): void {
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => [
        'schedule' => ['enabled' => false, 'prune_expired' => '0 3 * * *'],
        'scaffold' => ['namespace' => '\\Domain\\Guards\\', 'path' => 'src/Guards/'],
    ]]));

    expect($config->scheduleEnabled())->toBeFalse()
        ->and($config->pruneExpiredFrequency())->toBe('0 3 * * *')
        ->and($config->scaffoldNamespace())->toBe('Domain\\Guards')
        ->and($config->scaffoldPath())->toBe('src/Guards')
        ->and(AzGuardConfig::fromRepository(new Repository(['azguard' => ['schedule' => ['prune_expired' => null]]]))->pruneExpiredFrequency())->toBeNull();
});

it('rejects a value outside its type in the new sections', function (array $azguard, string $key): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => $azguard])))
        ->toThrow(InvalidConfigurationException::class, 'azguard.'.$key);
})->with([
    'gate.enabled as a string' => [['gate' => ['enabled' => 'yes']], 'gate.enabled'],
    'schedule.enabled as a number' => [['schedule' => ['enabled' => 1]], 'schedule.enabled'],
    'a misspelled frequency' => [['schedule' => ['prune_expired' => 'dayly']], 'schedule.prune_expired'],
    'a frequency that needs an argument' => [['schedule' => ['prune_expired' => 'dailyAt']], 'schedule.prune_expired'],
    'cron fields outside their ranges' => [['schedule' => ['prune_expired' => '99 99 * * *']], 'schedule.prune_expired'],
    'five words instead of cron fields' => [['schedule' => ['prune_expired' => 'nonsense cron is accepted here']], 'schedule.prune_expired'],
    'a frequency with no days' => [['schedule' => ['prune_expired' => 'daysOfMonth']], 'schedule.prune_expired'],
    'a frequency that is a number' => [['schedule' => ['prune_expired' => 5]], 'schedule.prune_expired'],
    'a namespace with a dash' => [['scaffold' => ['namespace' => 'App\\My-Guards']], 'scaffold.namespace'],
    'an absolute generator path' => [['scaffold' => ['path' => '/var/guards']], 'scaffold.path'],
    'a Windows absolute generator path' => [['scaffold' => ['path' => 'C:\\outside']], 'scaffold.path'],
    'a Windows drive-relative generator path' => [['scaffold' => ['path' => 'C:outside']], 'scaffold.path'],
    'a generator stream wrapper' => [['scaffold' => ['path' => 'php://filter']], 'scaffold.path'],
    'a generator path leaving the application' => [['scaffold' => ['path' => '../guards']], 'scaffold.path'],
    'resolvers as a map' => [['defaults' => ['tenants' => ['resolvers' => ['a' => FixedTenantResolver::class]]]], 'defaults.tenants.resolvers'],
    'a resolver that is not a class' => [['defaults' => ['scopes' => ['resolvers' => ['Nope\\Missing']]]], 'defaults.scopes.resolvers'],
    'a resolver that is a closure' => [['defaults' => ['scopes' => ['resolvers' => [fn () => null]]]], 'defaults.scopes.resolvers'],
]);

/**
 * @param  list<class-string>  $tenants
 * @param  list<class-string>  $scopes
 */
function compilerWithResolvers(array $tenants, array $scopes): PanelCompiler
{
    $config = AzGuardConfig::fromRepository(new Repository(['azguard' => ['defaults' => [
        'tenants' => ['resolvers' => $tenants], 'scopes' => ['resolvers' => $scopes],
    ]]]));

    return new PanelCompiler(
        static fn (): array => $config->defaults(),
        static fn (): array => ['tenants' => $config->defaultTenantResolvers(), 'scopes' => $config->defaultScopeResolvers()],
    );
}

it('gives every panel the resolvers of the configuration after its own', function (): void {
    $recipe = new PanelRecipe('admin');
    $own = new FixedTenantResolver;
    (new PanelBuilder($recipe))->for(User::class)->tenantResolvers([$own]);
    $recipe->seal();
    $panel = compilerWithResolvers([FixedTenantResolver::class], [FixedScopeResolver::class])->compile($recipe);

    expect($panel->tenantResolvers())->toBe([$own, FixedTenantResolver::class])
        ->and($panel->scopeResolvers())->toBe([FixedScopeResolver::class]);
});

it('rejects a configured resolver that does not implement its contract', function (): void {
    $recipe = new PanelRecipe('admin');
    (new PanelBuilder($recipe))->for(User::class);
    $recipe->seal();

    expect(fn () => compilerWithResolvers([FixedScopeResolver::class], [])->compile($recipe))
        ->toThrow(InvalidConfigurationException::class, 'azguard.defaults.tenants.resolvers lists '.FixedScopeResolver::class);
});

it('publishes the configuration file under azguard-config and the migrations under azguard-migrations', function (): void {
    $config = AzGuardServiceProvider::pathsToPublish(AzGuardServiceProvider::class, 'azguard-config');
    $migrations = AzGuardServiceProvider::pathsToPublish(AzGuardServiceProvider::class, 'azguard-migrations');

    expect(array_values($config))->toBe([config_path('azguard.php')])
        ->and(realpath((string) array_key_first($config)))->toBe(realpath(dirname(__DIR__, 3).'/packages/core/config/azguard.php'))
        ->and(array_values($migrations))->toBe([database_path('migrations')]);
});

/** How many `before` callbacks the Gate of the booted application has. */
function gateBeforeCallbacks(): int
{
    $gate = app(Gate::class);

    return count((new ReflectionProperty($gate, 'beforeCallbacks'))->getValue($gate));
}

it('registers Gate::before only while gate.enabled is true', function (): void {
    $this->bootWith(['azguard.gate.enabled' => true]);
    $enabled = gateBeforeCallbacks();
    $this->bootWith(['azguard.gate.enabled' => false]);

    expect(gateBeforeCallbacks())->toBe($enabled - 1);
});
