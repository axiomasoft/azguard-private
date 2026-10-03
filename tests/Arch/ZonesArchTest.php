<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

/*
 * Zone boundaries of the core. A rule for a zone without code yet is vacuous and becomes active
 * with the first class in that zone.
 *
 * Every `not->toUse` rule has exactly one subject: Pest negates "all subjects use X", so a list of
 * subjects would pass as soon as one of them does not use X.
 */

const AZGUARD_OUTER_ZONES = [
    'AzGuard\Contracts', 'AzGuard\Panels', 'AzGuard\Catalog', 'AzGuard\Scopes', 'AzGuard\Sources',
    'AzGuard\Policies', 'AzGuard\Authorization', 'AzGuard\Changes', 'AzGuard\Schema', 'AzGuard\Storage',
    'AzGuard\Plugins', 'AzGuard\Laravel', 'AzGuard\Testing', 'AzGuard\Roles', 'AzGuard\Configuration',
    'AzGuard\Diagnostics', 'AzGuard\Internal', 'AzGuard\Facades', 'AzGuard\Concerns', 'AzGuard\Events',
    'AzGuard\Attributes',
];

/**
 * @param  list<string>  $subjects
 * @param  list<string>  $forbidden
 * @param  list<string>  $ignoring
 */
function forbidDependencies(string $rule, array $subjects, array $forbidden, array $ignoring = []): void
{
    foreach ($subjects as $subject) {
        $expectation = arch("{$rule} ({$subject})")->expect($subject)->not->toUse($forbidden);

        if ($ignoring !== []) {
            $expectation->ignoring($ignoring);
        }
    }
}

const AZGUARD_DATABASE_ACCESS = [
    'Illuminate\Support\Facades\DB',
    'Illuminate\Support\Facades\Schema',
    'Illuminate\Database\Connection',
    'Illuminate\Database\ConnectionInterface',
    'Illuminate\Database\Schema',
];

arch('kernel depends on nothing but PHP')
    ->expect('AzGuard\Kernel')
    ->not->toUse(['Illuminate', 'Carbon', 'Laravel', ...AZGUARD_OUTER_ZONES]);

const AZGUARD_FRAMEWORK_HELPERS = ['app', 'config', 'now', 'today', 'env', 'resolve', 'request', 'auth', 'cache', 'event', 'logger', 'info'];

it('finds framework helper calls but not methods with the same name', function (): void {
    $code = <<<'PHP'
        <?php
        namespace AzGuard\Kernel\X;
        $a = now();
        $b = \config('azguard.x');
        $c = $object->info();
        $d = Clock::now();
        $e = new Cache();
        function event(): void {}
        PHP;

    expect(SourceScan::functionCalls($code, AZGUARD_FRAMEWORK_HELPERS))->toBe(['now', 'config']);
});

it('keeps the kernel free of framework helper calls', function (): void {
    expect(SourceScan::callsIn(SourceScan::files('packages/core/src/Kernel'), AZGUARD_FRAMEWORK_HELPERS))->toBe([]);
});

arch('exceptions depend only on the kernel')
    ->expect('AzGuard\Exceptions')
    ->not->toUse(['Illuminate', ...AZGUARD_OUTER_ZONES]);

arch('contracts do not import implementations')
    ->expect('AzGuard\Contracts')
    ->not->toUse([
        'AzGuard\Authorization', 'AzGuard\Changes', 'AzGuard\Storage', 'AzGuard\Sources', 'AzGuard\Laravel',
        'AzGuard\Internal', 'AzGuard\Testing', 'AzGuard\Diagnostics', 'AzGuard\Configuration',
    ]);

forbidDependencies('authorization and schema never change access', ['AzGuard\Authorization', 'AzGuard\Schema'], ['AzGuard\Changes']);

forbidDependencies(
    'panels, catalog, scopes, policies and schema stay off storage and changes',
    ['AzGuard\Panels', 'AzGuard\Catalog', 'AzGuard\Scopes', 'AzGuard\Policies', 'AzGuard\Schema'],
    ['AzGuard\Storage', 'AzGuard\Changes'],
);

forbidDependencies(
    'configuration depends only on the kernel, exceptions and the framework',
    ['AzGuard\Configuration'],
    array_values(array_diff(AZGUARD_OUTER_ZONES, ['AzGuard\Configuration'])),
);

forbidDependencies(
    'the catalog neither decides access nor reaches the framework adapters',
    ['AzGuard\Catalog'],
    ['AzGuard\Authorization', 'AzGuard\Laravel'],
);

arch('sources do not use changes or authorization')
    ->expect('AzGuard\Sources')
    ->not->toUse(['AzGuard\Changes', 'AzGuard\Authorization']);

arch('storage does not use authorization or changes')
    ->expect('AzGuard\Storage')
    ->not->toUse(['AzGuard\Authorization', 'AzGuard\Changes']);

arch('code roles do not use storage, changes or authorization')
    ->expect('AzGuard\Roles')
    ->not->toUse(['AzGuard\Storage', 'AzGuard\Changes', 'AzGuard\Authorization']);

arch('only storage and the database source touch the database layer')
    ->expect(AZGUARD_DATABASE_ACCESS)
    ->toOnlyBeUsedIn(['AzGuard\Storage', 'AzGuard\Sources\Database'])
    ->ignoring('AzGuard\Tests');

forbidDependencies(
    'sources other than the database source and plugins stay off storage',
    ['AzGuard\Sources', 'AzGuard\Plugins'],
    ['AzGuard\Storage'],
    ['AzGuard\Sources\Database'],
);

arch('grants are stored only through the change pipeline, the database source or test helpers')
    ->expect('AzGuard\Contracts\Sources\StoresGrants')
    ->toOnlyBeUsedIn([
        'AzGuard\Changes\ChangePipeline', 'AzGuard\Sources\Database', 'AzGuard\Testing',
        'AzGuard\Panels\Panel', 'AzGuard\Sources\PanelSources',
    ])
    ->ignoring('AzGuard\Tests');

// Subjects are listed: ignoring('AzGuard\Testing') would also drop the dependency under test.
forbidDependencies(
    'production code does not import test helpers',
    [
        'AzGuard\AzGuardServiceProvider', 'AzGuard\Kernel', 'AzGuard\Exceptions', 'AzGuard\Filament',
        ...array_values(array_diff(AZGUARD_OUTER_ZONES, ['AzGuard\Testing'])),
    ],
    ['AzGuard\Testing'],
);

arch('plugins do not reach into internals')
    ->expect('AzGuard\Plugins')
    ->not->toUse('AzGuard\Internal');

/**
 * @return list<string> `transaction` calls; comments and strings are not calls
 */
function azguardTransactionCalls(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $calls = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'transaction') {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $called = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

        if ($called && $next === '(') {
            $calls[] = 'transaction';
        }
    }

    return $calls;
}

it('rejects a StoresGrants transaction() call copied into the panel zone', function (): void {
    $directory = sys_get_temp_dir().'/azguard-stores-grants-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $copy = $directory.'/Panel.php';
    file_put_contents($copy, <<<'PHP'
        <?php
        namespace AzGuard\Panels;
        use AzGuard\Contracts\Sources\StoresGrants;
        final class Panel
        {
            public function writer(): ?StoresGrants { return null; }
            public function bad(StoresGrants $writer): mixed
            {
                return $writer->transaction(static fn (): null => null);
            }
        }
        PHP);

    $root = dirname(__DIR__, 2);

    expect(azguardTransactionCalls($copy))->toBe(['transaction'])
        ->and(azguardTransactionCalls($root.'/packages/core/src/Panels/Panel.php'))->toBe([])
        ->and(azguardTransactionCalls($root.'/packages/core/src/Sources/PanelSources.php'))->toBe([]);
});
