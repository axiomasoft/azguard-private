<?php

declare(strict_types=1);

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\Severity;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;

/*
 * I17, blocking now: every doctor check class with its key and the finding ids it emits, and the document
 * `azguard:doctor --json` prints (field names, severities, exit codes).
 */

afterEach(fn () => CrmWorld::resetRuntime());

/** @return list<class-string<DoctorCheck>> every production doctor check, sorted by class */
function baselineDoctorChecks(): array
{
    $checks = [];
    $root = dirname(__DIR__, 3).'/packages';

    foreach (FormatSnapshot::productionRoots($root) as $directory => $namespace) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || ! str_contains((string) file_get_contents($file->getPathname()), 'DoctorCheck')) {
                continue;
            }
            $class = $namespace.str_replace('/', '\\', substr($file->getPathname(), strlen($root.'/'.$directory) + 1, -4));

            if (class_exists($class) && is_subclass_of($class, DoctorCheck::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $checks[] = $class;
            }
        }
    }
    sort($checks, SORT_STRING);

    return $checks;
}

/** @return array{literal: list<string>, uses_key: bool} the finding ids the source of a check passes to DoctorFinding */
function baselineFindingIds(string $class): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents((string) (new ReflectionClass($class))->getFileName())),
        static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    $literal = [];
    $usesKey = false;

    foreach ($tokens as $index => $token) {
        if ($token->text !== 'DoctorFinding' || ($tokens[$index + 1]->text ?? '') !== '::' || ! in_array($tokens[$index + 2]->text ?? '', ['error', 'warning'], true)) {
            continue;
        }
        $first = $tokens[$index + 4] ?? null;

        if ($first?->id === T_CONSTANT_ENCAPSED_STRING) {
            $literal[] = trim($first->text, '\'"');
        } elseif ($first?->text === '$this' && ($tokens[$index + 6]->text ?? '') === 'key') {
            $usesKey = true;
        }
    }
    $literal = array_values(array_unique($literal));
    sort($literal, SORT_STRING);

    return ['literal' => $literal, 'uses_key' => $usesKey];
}

/** @param list<array<string, mixed>> $findings */
function baselineDoctorDocument(int $exit, array $findings): array
{
    return [
        'exit' => $exit,
        'finding_fields' => array_values(array_unique(array_merge(...array_map(array_keys(...), $findings ?: [[]])))),
        'findings' => array_map(static fn (array $finding): array => ['key' => $finding['key'], 'severity' => $finding['severity'], 'scope' => $finding['scope'],
            'details' => array_keys($finding['details'])], $findings),
    ];
}

it('keeps every doctor check class, its key and the finding ids it emits', function (): void {
    $checks = [];

    foreach (baselineDoctorChecks() as $class) {
        $checks[$class] = ['key' => (new ReflectionClass($class))->newInstanceWithoutConstructor()->key(), 'finding_ids' => baselineFindingIds($class)];
    }
    $snapshot = ['severities' => array_map(static fn (Severity $severity): string => $severity->value, Severity::cases()),
        'failed_check_id' => '<key>.failed', 'checks' => $checks];
    [$actual, $stored] = FormatSnapshot::compare('doctor-checks', $snapshot);

    expect($actual)->toBe($stored);
});

it('keeps the doctor JSON document of a healthy and a broken CRM stand on SQLite', function (): void {
    $previousMorphMap = Relation::morphMap();

    try {
        CrmWorld::seed();
        Relation::morphMap(['crm.project' => Project::class]);
        CrmWorld::compile();
        $healthy = Artisan::call('azguard:doctor', ['--json' => true]);
        $healthyFindings = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        CrmWorld::storage()->connection()->getSchemaBuilder()->drop('azg_panel_state');
        $broken = Artisan::call('azguard:doctor', ['--json' => true]);
        $brokenFindings = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($healthy)->toBe(0)->and($broken)->toBe(1);

        [$actual, $stored] = FormatSnapshot::compare('doctor', [
            'healthy' => baselineDoctorDocument($healthy, $healthyFindings),
            'broken' => baselineDoctorDocument($broken, $brokenFindings),
            'unknown_panel_exit' => Artisan::call('azguard:doctor', ['--json' => true, '--panel' => ['ghost']]),
        ]);

        expect($actual)->toBe($stored);
    } finally {
        Relation::morphMap($previousMorphMap, false);
    }
})->group('sqlite', 'server-skip'); // the storage checks of the stand differ by driver (storage.sqlite, consistency.reads)

it('pins the production cache security findings in the doctor format', function (): void {
    config(['cache.stores.shared.driver' => 'redis', 'azguard.cache.namespace' => null,
        'azguard.cache.signing_key' => null, 'app.key' => null]);
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()])->cache(store: 'shared', ttl: 4000)]);
    $exit = Artisan::call('azguard:doctor', ['--production' => true, '--json' => true]);
    $findings = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $findings = array_values(array_filter($findings, static fn (array $finding): bool => str_starts_with($finding['key'], 'cache.')));
    expect($exit)->toBe(1)->and(array_column($findings, 'key'))->toBe(['cache.redis', 'cache.signing', 'cache.ttl']);
    $signing = array_values(array_filter($findings, static fn (array $finding): bool => $finding['key'] === 'cache.signing'));
    expect($signing[0]['severity'])->toBe('error');
    [$actual, $stored] = FormatSnapshot::compare('doctor-cache-v2', baselineDoctorDocument($exit, $findings));
    expect($actual)->toBe($stored);
});
