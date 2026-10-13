<?php

declare(strict_types=1);

use AzGuard\Configuration\ConfigSections;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\DevTools\Internal\ScaffoldConfig;
use AzGuard\Exceptions\InvalidConfigurationException;
use Illuminate\Config\Repository;

/*
 * An owner other than core validates its root section of config/azguard.php through ConfigSections; core still
 * rejects every root nobody owns, and registration closes when the configuration is read.
 */

/** @param array<string, mixed> $azguard */
function sectionsConfig(array $azguard, ConfigSections $sections): AzGuardConfig
{
    return AzGuardConfig::fromRepository(new Repository(['azguard' => $azguard]), $sections);
}

it('hands a registered root to its owner and still rejects a root nobody owns', function (): void {
    $seen = [];
    $sections = new ConfigSections;
    $sections->own('reports', static function (array $section) use (&$seen): void {
        $seen[] = $section;

        if (($section['format'] ?? 'pdf') !== 'pdf') {
            throw new InvalidConfigurationException('azguard.reports.format must be pdf.');
        }
    });

    sectionsConfig(['reports' => ['format' => 'pdf']], $sections);
    sectionsConfig([], $sections);

    expect($seen)->toBe([['format' => 'pdf'], []])
        ->and(fn () => sectionsConfig(['reports' => ['format' => 'csv']], $sections))->toThrow(InvalidConfigurationException::class, 'must be pdf')
        ->and(fn () => sectionsConfig(['reports' => [], 'unknown_root' => []], $sections))->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard: unknown_root')
        ->and(fn () => sectionsConfig(['reports' => 'pdf'], $sections))->toThrow(InvalidConfigurationException::class, 'azguard.reports must be an array')
        ->and(fn () => sectionsConfig(['reports' => ['pdf']], $sections))->toThrow(InvalidConfigurationException::class, 'azguard.reports must be a map');
});

it('rejects a root without an owner that only a module would accept', function (): void {
    expect(fn () => sectionsConfig(['reports' => []], new ConfigSections))->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard: reports');
});

it('refuses a second owner of a root, a malformed root and a registration after the configuration was read', function (): void {
    $sections = new ConfigSections;
    $sections->own('reports', static function (array $section): void {});

    expect(fn () => $sections->own('reports', static function (array $section): void {}))->toThrow(InvalidConfigurationException::class, 'already has an owner')
        ->and(fn () => $sections->own('Reports', static function (array $section): void {}))->toThrow(InvalidConfigurationException::class, 'lowercase root key')
        ->and(fn () => $sections->own('', static function (array $section): void {}))->toThrow(InvalidConfigurationException::class, 'lowercase root key');

    sectionsConfig([], $sections);

    expect(fn () => $sections->own('exports', static function (array $section): void {}))->toThrow(InvalidConfigurationException::class, 'after the configuration was read');
});

it('refuses an owner of a root that belongs to core when the configuration is read', function (string $root): void {
    $sections = new ConfigSections;
    $sections->own($root, static function (array $section): void {});

    expect(fn () => sectionsConfig([], $sections))->toThrow(InvalidConfigurationException::class, 'belongs to core');
})->with(['panels', 'storages', 'defaults', 'gate', 'decision_sets']);

it('validates the scaffold section through the owner DevTools registers', function (): void {
    $sections = app(ConfigSections::class);
    $repository = new Repository(['azguard' => ['scaffold' => ['namespace' => 'App\\Access', 'path' => 'app/Access']]]);
    AzGuardConfig::fromRepository($repository, $sections);

    expect(array_keys($sections->owned()))->toContain('scaffold')
        ->and(ScaffoldConfig::fromRepository($repository)->namespace)->toBe('App\\Access')->and(ScaffoldConfig::fromRepository($repository)->path)->toBe('app/Access')
        ->and(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['scaffold' => ['folder' => 'x']]]), $sections))
        ->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard.scaffold: folder')
        ->and(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['scaffold' => ['path' => '/abs']]]), $sections))
        ->toThrow(InvalidConfigurationException::class, 'azguard.scaffold.path must be')
        ->and(fn () => sectionsConfig(['scaffold' => []], new ConfigSections))->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard: scaffold');
});

it('gives the generators the discovery folder names core reads, with the same defaults and the same refusal', function (): void {
    $custom = new Repository(['azguard' => ['discovery' => ['permissions' => 'Abilities/Perms/', 'shared' => 'Common']]]);

    foreach ([new Repository(['azguard' => []]), $custom] as $repository) {
        $core = AzGuardConfig::fromRepository($repository)->discovery();
        $scaffold = ScaffoldConfig::fromRepository($repository);

        expect(array_combine(array_keys($core), array_map($scaffold->folder(...), array_keys($core))))->toBe($core);
    }

    expect(ScaffoldConfig::fromRepository($custom)->folder('unknown'))->toBeNull()
        ->and(fn () => ScaffoldConfig::fromRepository(new Repository(['azguard' => ['discovery' => ['roles' => '']]])))
        ->toThrow(InvalidConfigurationException::class, 'azguard.discovery.roles must be a folder name')
        ->and(fn () => ScaffoldConfig::fromRepository(new Repository(['azguard' => ['scaffold' => ['folder' => 'x']]])))
        ->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard.scaffold: folder');
});
