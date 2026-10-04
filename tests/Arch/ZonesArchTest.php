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
    'AzGuard\Attributes', 'AzGuard\Permissions', 'AzGuard\Directories',
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
    'directories do not decide access or touch storage and changes',
    ['AzGuard\Directories'],
    ['AzGuard\Storage', 'AzGuard\Changes', 'AzGuard\Authorization'],
);

it('rejects a directory file that imports storage, changes or authorization', function (): void {
    $directory = sys_get_temp_dir().'/azguard-directory-zone-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $copy = $directory.'/LookupContext.php';
    file_put_contents($copy, <<<'PHP'
        <?php
        namespace AzGuard\Directories;
        use AzGuard\Storage\Models\Grant;
        use AzGuard\Changes\Change;
        use AzGuard\Authorization\Decision;
        final class LookupContext {}
        PHP);

    $root = dirname(__DIR__, 2);
    $files = SourceScan::files('packages/core/src/Directories');
    $zones = ['AzGuard\Authorization', 'AzGuard\Changes', 'AzGuard\Storage'];

    expect(azguardZoneImports($copy, $zones))->toBe($zones)
        ->and(array_merge(...array_map(
            static fn (string $file): array => azguardZoneImports($file, $zones),
            $files,
        )))->toBe([]);
});

it('rejects a policy file that imports storage or changes', function (): void {
    $directory = sys_get_temp_dir().'/azguard-policy-zone-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $copy = $directory.'/PolicyBinding.php';
    file_put_contents($copy, <<<'PHP'
        <?php
        namespace AzGuard\Policies;
        use AzGuard\Storage\Models\Grant;
        use AzGuard\Changes\Change;
        final class PolicyBinding {}
        PHP);

    $root = dirname(__DIR__, 2);
    $policies = SourceScan::files('packages/core/src/Policies');

    expect(azguardZoneImports($copy, ['AzGuard\Storage', 'AzGuard\Changes']))->toBe(['AzGuard\Changes', 'AzGuard\Storage'])
        ->and(array_merge(...array_map(
            static fn (string $file): array => azguardZoneImports($file, ['AzGuard\Storage', 'AzGuard\Changes']),
            $policies,
        )))->toBe([]);
});

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

it('only storage and database sources call storage models statically', function (): void {
    expect(SourceScan::modelStaticCallsIn(SourceScan::files()))->toBe([]);
});

it('only storage database sources change pipeline and testing use storage mutation', function (): void {
    expect(SourceScan::restrictedReferencesIn(SourceScan::files(), ['AzGuard\Storage\StorageMutation'], [
        'AzGuard\Storage', 'AzGuard\Sources\Database', 'AzGuard\Changes\ChangePipeline', 'AzGuard\Testing',
    ]))->toBe([]);
});

it('only storage uses storage concerns and the guarded builder', function (): void {
    expect(SourceScan::restrictedReferencesIn(SourceScan::files(), [
        'AzGuard\Storage\Concerns', 'AzGuard\Storage\WriteGuardedBuilder',
    ], ['AzGuard\Storage']))->toBe([]);
});

it('resolves model calls through imports aliases inheritance and class-relative names', function (): void {
    $directory = sys_get_temp_dir().'/azguard-model-scan-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/Calls.php';
    file_put_contents($file, <<<'PHP'
        <?php
        namespace AzGuard\Panels;
        use AzGuard\Storage\Models\{RoleGrant as Grant, PermissionGrant};
        use AzGuard\Storage as Store;
        class Custom extends Grant {
            public function bad(): void {
                self::query(); static::query(); parent::query();
                Grant::query(); PermissionGrant::query(); Store\Models\Permission::query();
                \AzGuard\Storage\Models\RoleGrant::query();
                $a = 'RoleGrant::query()'; $b = Grant::class; $c = $object->query();
            }
        }
        class Child extends Custom { public function bad(): void { self::query(); } }
        PHP);
    $child = $directory.'/Descendant.php';
    file_put_contents($child, <<<'PHP'
        <?php
        namespace AzGuard\Filament;
        use AzGuard\Panels\Custom as Imported;
        class Descendant extends Imported { public function bad(): void { parent::query(); } }
        PHP);

    try {
        expect(SourceScan::modelStaticCallsIn([$file]))->toHaveCount(8)
            ->and(SourceScan::modelStaticCallsIn([$child, $file]))->toHaveCount(9)
            ->and(SourceScan::imports((string) file_get_contents($file)))->toBe([
                'grant' => 'AzGuard\Storage\Models\RoleGrant',
                'permissiongrant' => 'AzGuard\Storage\Models\PermissionGrant',
                'store' => 'AzGuard\Storage',
            ]);
        file_put_contents($file, str_replace('namespace AzGuard\Panels;', 'namespace AzGuard\Storage;', (string) file_get_contents($file)));
        expect(SourceScan::modelStaticCallsIn([$file]))->toBe([]);
    } finally {
        unlink($child);
        unlink($file);
        rmdir($directory);
    }
});

it('resolves grouped mutation imports and fully qualified internal dependencies', function (): void {
    $directory = sys_get_temp_dir().'/azguard-write-zones-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/Calls.php';
    $code = <<<'PHP'
        <?php
        namespace AzGuard\Sources\Folder;
        use AzGuard\Storage\{StorageMutation as Mutation, WriteGuardedBuilder};
        final class Lookup {
            use \AzGuard\Storage\Concerns\BelongsToStorage;
            public function bad(Mutation $mutation): WriteGuardedBuilder {}
        }
        PHP;
    file_put_contents($file, $code);

    try {
        expect(azguardZoneImports($file, ['AzGuard\Storage\StorageMutation']))->toBe(['AzGuard\Storage\StorageMutation'])
            ->and(SourceScan::restrictedReferencesIn([$file], ['AzGuard\Storage\StorageMutation'], ['AzGuard\Storage']))->toHaveCount(1)
            ->and(SourceScan::restrictedReferencesIn([$file], ['AzGuard\Storage\Concerns', 'AzGuard\Storage\WriteGuardedBuilder'], ['AzGuard\Storage']))->toHaveCount(2);
        file_put_contents($file, str_replace(
            ['namespace AzGuard\Sources\Folder;', 'final class Lookup'],
            ['namespace AzGuard\Changes;', 'final class ChangePipeline'],
            $code,
        ));
        expect(SourceScan::restrictedReferencesIn([$file], ['AzGuard\Storage\StorageMutation'], ['AzGuard\Changes\ChangePipeline']))->toBe([]);
        file_put_contents($file, str_replace('final class Lookup', 'final class ChangePipelineOther', str_replace('namespace AzGuard\Sources\Folder;', 'namespace AzGuard\Changes;', $code)));
        expect(SourceScan::restrictedReferencesIn([$file], ['AzGuard\Storage\StorageMutation'], ['AzGuard\Changes\ChangePipeline']))->toHaveCount(1);
    } finally {
        unlink($file);
        rmdir($directory);
    }
});

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
        'AzGuard\Authorization\Pipeline\Stages\AuthorityStage', // read-only provenance check; no transaction calls

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
/**
 * @param  list<string>  $zones
 * @return list<string> imported zones, sorted
 */
function azguardZoneImports(string $file, array $zones): array
{
    $found = [];
    foreach (SourceScan::imports((string) file_get_contents($file)) as $name) {
        foreach ($zones as $zone) {
            if ($name === $zone || str_starts_with($name, $zone.'\\')) {
                $found[] = $zone;
            }
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

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
