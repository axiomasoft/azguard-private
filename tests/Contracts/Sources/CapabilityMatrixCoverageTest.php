<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Panels\AdminFilamentProvider;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use Filament\FilamentServiceProvider;
use Filament\Panel as FilamentPanel;
use Filament\PanelRegistry as FilamentPanelRegistry;
use Illuminate\Database\Eloquent\Model;
use Livewire\LivewireServiceProvider;
use PHPUnit\Logging\TestDox\NamePrettifier;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

#[Role('coverage-queryable-folder-auto')]
#[NotGrantable]
final class CoverageQueryableFolderAutomaticRole extends BaseRole implements GrantedAutomatically, SelectsAutomaticRoleScopes
{
    public function permissions(): array
    {
        return ['archived-orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getKey() === 1 && $scope->context->type() === 'project' && $scope->context->id() === '1';
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        return $subject->getKey() === 1 && $contextType === 'project' ? [W::scope(1)] : [];
    }
}

#[Role('coverage-unqueryable-folder-auto')]
#[NotGrantable]
final class CoverageUnqueryableFolderAutomaticRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return ($subject->getKey() === 1 && $scope->context->type() === 'project' && $scope->context->id() === '1')
            || ($subject->getKey() === 2 && $scope->tenant->equals(W::tenant('A')) && $scope->context->isGlobal());
    }
}

/**
 * Each cell names an executable source-specific scenario in this required lane. Conditional cells identify a
 * supported scenario or a typed refusal; they do not assert that every arbitrary configuration is queryable.
 * The guard also locks the status so a new claim cannot silently reuse an old scenario.
 *
 * @return array<string, array<string, array{string, string, string}>>
 */
function builtInCapabilityCoverage(): array
{
    $folder = ['QueryParityContractTest.php', 'matches scalar batch and exact visibility for a folder automatic role'];
    $database = ['QueryParityContractTest.php', 'matches scalar batch and exact visibility for a scoped database grant'];
    $relation = ['RelationSourceContractTest.php', 'test_queryable_membership_matches_scalar_batch_and_exact_visibility'];
    $gate = ['GateSourceContractTest.php', 'test_gate_scalar_and_batch_agree_and_visibility_refuses_an_unqueryable_policy'];
    $filament = ['FilamentSourceContractTest.php', 'test_definitions_participate_in_scalar_batch_and_filament_checks'];
    $unstable = ['StableCacheCapabilityTest.php', 'built-in unsupported stable-cache sources cannot qualify for stable authority reuse'];
    $conditional = static fn (array $ref): array => ['conditional', ...$ref];
    $supported = static fn (array $ref): array => ['supported', ...$ref];
    $unsupported = static fn (array $ref): array => ['unsupported', ...$ref];

    return [
        'folder' => [
            'scalar' => $supported($folder), 'batch' => $supported($folder), 'exact_visibility' => $conditional($folder),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['QueryParityContractTest.php', 'enforces tenant membership for folder automatic roles in inherit mode']),
            'filament' => $conditional(['CapabilityMatrixCoverageTest.php', 'grants a filament resource through a queryable folder automatic role and filters visible records']),
        ],
        'folder_automatic_role' => [
            'scalar' => $supported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'batch' => $supported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'exact_visibility' => $unsupported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['CapabilityMatrixCoverageTest.php', 'enforces tenant membership for unqueryable folder automatic roles in inherit mode']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_folder_automatic_role_grants_a_filament_resource_only_to_its_members']),
        ],
        'database' => [
            'scalar' => $supported($database), 'batch' => $supported($database), 'exact_visibility' => $conditional($database),
            'stable_cache' => $conditional(['DatabaseCacheContractTest.php', 'reuses stable database authority and invalidates it after grant and revoke']),
            'tenant_inheritance' => $conditional(['QueryParityContractTest.php', 'applies a tenant-wide database grant in inherit mode without crossing tenants']),
            'filament' => $conditional($filament),
        ],
        'relation' => [
            'scalar' => $supported($relation), 'batch' => $supported($relation), 'exact_visibility' => $conditional($relation),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['RelationTenantContractTest.php', 'keeps relation membership inside tenant and context in inherit mode']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_relation_membership_controls_filament_record_and_visible_query']),
        ],
        'gate' => [
            'scalar' => $supported($gate), 'batch' => $supported($gate), 'exact_visibility' => $conditional($gate),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['GateSourceContractTest.php', 'test_tenant_membership_still_gates_native_policy_decisions']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_gate_source_decides_filament_resource_definitions']),
        ],
        'filament' => [
            'scalar' => $supported($filament), 'batch' => $supported($filament),
            'exact_visibility' => $conditional(['CapabilityMatrixCoverageTest.php', 'filters exact visibility for a filament resource permission definition']),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['FilamentSourceContractTest.php', 'test_filament_tenant_context_inheritance_in_inherit_mode']),
            'filament' => $conditional($filament),
        ],
    ];
}

/** @return array<string, array<string, array{string, string}>> */
function builtInTenantModeCoverage(): array
{
    return [
        'folder' => [
            'inherit' => ['QueryParityContractTest.php', 'enforces tenant membership for folder automatic roles in inherit mode'],
            'isolated' => ['QueryParityContractTest.php', 'enforces tenant membership for folder automatic roles in isolated mode'],
        ],
        'folder_automatic_role' => [
            'inherit' => ['CapabilityMatrixCoverageTest.php', 'enforces tenant membership for unqueryable folder automatic roles in inherit mode'],
            'isolated' => ['CapabilityMatrixCoverageTest.php', 'enforces tenant membership for unqueryable folder automatic roles in isolated mode'],
        ],
        'database' => [
            'inherit' => ['QueryParityContractTest.php', 'applies a tenant-wide database grant in inherit mode without crossing tenants'],
            'isolated' => ['QueryParityContractTest.php', 'applies a tenant-wide database grant in isolated mode without crossing tenants'],
        ],
        'relation' => [
            'inherit' => ['RelationTenantContractTest.php', 'keeps relation membership inside tenant and context in inherit mode'],
            'isolated' => ['RelationTenantContractTest.php', 'keeps relation membership inside tenant and context in isolated mode'],
        ],
        'gate' => [
            'inherit' => ['GateSourceContractTest.php', 'test_gate_tenant_context_inherit_mode'],
            'isolated' => ['GateSourceContractTest.php', 'test_gate_tenant_context_isolated_mode'],
        ],
        'filament' => [
            'inherit' => ['FilamentSourceContractTest.php', 'test_filament_tenant_context_inheritance_in_inherit_mode'],
            'isolated' => ['FilamentSourceContractTest.php', 'test_filament_tenant_context_inheritance_in_isolated_mode'],
        ],
    ];
}

/** @return list<string> */
function builtInTenantModeCoverageErrors(array $variants): array
{
    $errors = [];
    foreach (['folder', 'folder_automatic_role', 'database', 'relation', 'gate', 'filament'] as $profile) {
        foreach (['inherit', 'isolated'] as $mode) {
            $scenario = $variants[$profile][$mode] ?? null;

            if (! is_array($scenario) || count($scenario) !== 2) {
                $errors[] = "$profile.tenant_inheritance.$mode is missing its mandatory scenario.";

                continue;
            }
            [$file, $name] = $scenario;

            if (! preg_match('/(?:^|[ _])'.preg_quote($mode, '/').'(?:[ _]|$)/i', $name)) {
                $errors[] = "$profile.tenant_inheritance.$mode must reference its distinct named mode scenario.";
            }
            $path = __DIR__.'/'.$file;
            $contents = is_file($path) ? file_get_contents($path) : false;
            $declaration = str_starts_with($name, 'test_') ? 'function '.$name.'(' : "it('".$name."'";

            if (! is_string($contents) || ! str_contains($contents, $declaration)) {
                $errors[] = "$profile.tenant_inheritance.$mode references a missing required-lane test.";
            }
        }
    }

    return $errors;
}

/** @return list<string> */
function builtInCapabilityCoverageErrors(array $matrix, array $coverage): array
{
    $errors = [];
    $classes = [
        'folder' => FolderSource::class,
        'folder_automatic_role' => FolderSource::class,
        'database' => DatabaseSource::class,
        'relation' => RelationSource::class,
        'gate' => GateSource::class,
        'filament' => FilamentSource::class,
    ];
    $profiles = array_keys($classes);

    if (array_diff($profiles, array_keys($coverage)) || array_diff(array_keys($coverage), $profiles)) {
        $errors[] = 'Built-in source profiles differ from the coverage map.';
    }
    // Variants may share a class; an entirely new built-in class needs a map entry.
    $coveredClasses = array_values(array_unique($classes));
    foreach ($matrix['sources'] ?? [] as $profile => $row) {
        $class = $row['class'] ?? '';
        $builtIn = str_starts_with($class, 'AzGuard\\Sources\\') || str_starts_with($class, 'AzGuard\\Filament\\Sources\\');

        if ($builtIn && (! in_array($class, $coveredClasses, true) || ! array_key_exists($profile, $classes))) {
            $errors[] = "$profile adds a built-in source profile without mandatory coverage.";
        }
    }
    foreach ($profiles as $profile) {
        if (($matrix['sources'][$profile]['class'] ?? null) !== $classes[$profile]) {
            $errors[] = "$profile changed its source class without revising its tests.";
        }
        $cells = $matrix['sources'][$profile]['matrix'] ?? [];
        $entries = $coverage[$profile] ?? [];
        foreach (array_unique([...array_keys($cells), ...array_keys($entries)]) as $cell) {
            if (! isset($cells[$cell], $entries[$cell]) || count($entries[$cell]) !== 3) {
                $errors[] = "$profile.$cell is missing a matrix cell or mandatory test.";

                continue;
            }
            [$status, $file, $name] = $entries[$cell];

            if ($status !== $cells[$cell]['status']) {
                $errors[] = "$profile.$cell changed status without revising its test.";
            }
            $path = __DIR__.'/'.$file;
            $text = is_file($path) ? file_get_contents($path) : false;
            $declaration = str_starts_with($name, 'test_') ? 'function '.$name.'(' : "it('".$name."'";

            if (! is_string($text) || ! str_contains($text, $declaration)) {
                $errors[] = "$profile.$cell references a missing required-lane test: $file::$name.";
            }
        }
    }

    return $errors;
}

/** @return list<string> */
function builtInCapabilityExecutionErrors(array $coverage): array
{
    $scenarios = [];
    foreach ($coverage as $cells) {
        foreach ($cells as [, $file, $name]) {
            $scenarios[$file][$name] = $name;
        }
    }
    foreach (builtInTenantModeCoverage() as $variants) {
        foreach ($variants as [$file, $name]) {
            $scenarios[$file][$name] = $name;
        }
    }

    $errors = [];
    $running = [];
    $root = dirname(__DIR__, 3);
    foreach ($scenarios as $file => $names) {
        $report = tempnam(sys_get_temp_dir(), 'azguard-capability-');
        $filter = '/(?:^|::)(?:it )?(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names)).')(?: with data set .*)?$/';
        $process = new Process([PHP_BINARY, $root.'/vendor/bin/pest', __DIR__.'/'.$file,
            '--filter='.$filter, '--log-junit='.$report, '--do-not-cache-result', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky', '--fail-on-empty-test-suite'], $root);
        $process->setTimeout(180);
        $process->start();
        $running[$file] = ['process' => $process, 'names' => array_values($names), 'report' => $report];

        if (count($running) < 4) {
            continue;
        }
        $errors = [...$errors, ...capabilityProcesses($running)];
        $running = [];
    }

    return [...$errors, ...capabilityProcesses($running)];
}

/** @param array<string, array{process: Process, names: list<string>, report: string}> $running @return list<string> */
function capabilityProcesses(array $running): array
{
    $errors = [];
    foreach ($running as $file => ['process' => $process, 'names' => $expected, 'report' => $report]) {
        try {
            $process->wait();

            if (! $process->isSuccessful()) {
                $errors[] = $file.' did not pass: '.$process->getOutput().$process->getErrorOutput();

                continue;
            }

            $xml = simplexml_load_file($report);
            $actual = [];
            foreach ($xml->xpath('//testcase') as $case) {
                // Pest prefixes closure tests with "it "; data sets belong to the same referenced scenario.
                $name = preg_replace('/ with data set .*$/', '', (string) $case['name']);
                $actual[] = str_starts_with($name, 'it ') ? substr($name, 3) : $name;
            }
            $actual = array_values(array_unique($actual));
            $prettifier = new NamePrettifier;
            $expected = array_map(static fn (string $name): string => str_starts_with($name, 'test_') ? $prettifier->prettifyTestMethodName($name) : $name, $expected);
            sort($actual);
            sort($expected);

            if ($actual !== $expected) {
                $errors[] = $file.' executed a different scenario set: expected '.json_encode($expected).'; got '.json_encode($actual);
            }
        } finally {
            @unlink($report);
        }
    }

    return $errors;
}

it('requires an executable source-specific contract for every built-in matrix cell', function (): void {
    $matrix = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/reference/source-capabilities.json'), true, flags: JSON_THROW_ON_ERROR);
    $coverage = builtInCapabilityCoverage();
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toBe([]);
    expect(builtInTenantModeCoverageErrors(builtInTenantModeCoverage()))->toBe([]);
    expect(builtInCapabilityExecutionErrors($coverage))->toBe([]);
});

it('fails the tenant mode guard when either scenario is missing or replaced', function (): void {
    foreach (['folder', 'folder_automatic_role', 'database', 'relation', 'gate', 'filament'] as $profile) {
        foreach (['inherit' => 'isolated', 'isolated' => 'inherit'] as $mode => $other) {
            $variants = builtInTenantModeCoverage();
            unset($variants[$profile][$mode]);
            expect(builtInTenantModeCoverageErrors($variants))->toContain("$profile.tenant_inheritance.$mode is missing its mandatory scenario.");
            $variants = builtInTenantModeCoverage();
            $variants[$profile][$mode] = $variants[$profile][$other];
            expect(builtInTenantModeCoverageErrors($variants))->toContain("$profile.tenant_inheritance.$mode must reference its distinct named mode scenario.");
        }
    }
});

it('fails the matrix guard when a built-in cell is removed or changes status', function (): void {
    $matrix = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/reference/source-capabilities.json'), true, flags: JSON_THROW_ON_ERROR);
    $coverage = builtInCapabilityCoverage();
    unset($coverage['relation']['filament']);
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->not->toBeEmpty();
    $coverage = builtInCapabilityCoverage();
    $matrix['sources']['relation']['matrix']['stable_cache']['status'] = 'conditional';
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->not->toBeEmpty();
    $matrix['sources']['new_source'] = ['class' => 'AzGuard\\Sources\\NewSource', 'matrix' => []];
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toContain('new_source adds a built-in source profile without mandatory coverage.');
    $matrix['sources']['new_source']['class'] = FolderSource::class;
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toContain('new_source adds a built-in source profile without mandatory coverage.');
});

it('fails the execution guard when a referenced scenario does not run', function (): void {
    $coverage = ['folder' => ['scalar' => ['supported', 'QueryParityContractTest.php', 'nonexistent matrix scenario']]];
    expect(builtInCapabilityExecutionErrors($coverage))->not->toBeEmpty();
});

function exerciseUnqueryableFolderAutomaticRoleTenantMode(string $mode): void
{
    W::seed();

    try {
        User::query()->insert(['id' => 2]);
        $membership = new Membership(members: ['1', '2']);
        [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource, static fn (PanelBuilder $builder) => $builder
            ->roles([CoverageUnqueryableFolderAutomaticRole::class])
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership)), mode: $mode, tenant: true);
        $resource = VisibilityClient::query()->findOrFail(101);
        $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource)->inTenant(W::tenant('A'));
        expect($authorizer->decide($panel, $request)->allowed())->toBeTrue()
            ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeTrue()
            ->and(fn () => $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope()))
            ->toThrow(VisibilityNotSupportedException::class, 'unsupported_selection');

        $second = SubjectRef::of('user', 2);
        $scoped = AccessRequest::for($second, PermissionKey::of('admin', 'orders.view'))->inScope(W::scope(1, 'A'), $resource);
        $expected = $mode === 'inherit';
        expect($authorizer->decide($panel, $scoped)->allowed())->toBe($expected)
            ->and($authorizer->decideMany([$scoped])->get(0)->allowed())->toBe($expected);

        $membership->members = [];
        expect($authorizer->decide($panel, $request)->allowed())->toBeFalse()
            ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeFalse();
    } finally {
        W::reset();
    }
}

it('enforces tenant membership for unqueryable folder automatic roles in inherit mode', function (): void {
    exerciseUnqueryableFolderAutomaticRoleTenantMode('inherit');
});

it('enforces tenant membership for unqueryable folder automatic roles in isolated mode', function (): void {
    exerciseUnqueryableFolderAutomaticRoleTenantMode('isolated');
});

function registerCoverageFilamentPanel(): void
{
    FilamentFixture::reset();
    FilamentFixture::ensure();
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    FilamentFixture::$abilities = ['view'];
    FilamentFixture::$resources = [ArchivedOrderResource::class];
    FilamentFixture::$pages = [];
    FilamentFixture::$widgets = [];
    app()->register(LivewireServiceProvider::class);
    app()->register(FilamentServiceProvider::class);
    app(FilamentPanelRegistry::class)->register((new AdminFilamentProvider(app()))->panel(FilamentPanel::make()));
}

it('grants a filament resource through a queryable folder automatic role and filters visible records', function (): void {
    W::seed();
    registerCoverageFilamentPanel();

    try {
        User::query()->insert(['id' => 2]);
        [$visibility, $panel, $authorizer] = W::compile(
            new VisibilitySource,
            static fn (PanelBuilder $builder) => $builder->roles([CoverageQueryableFolderAutomaticRole::class]),
            extra: [FilamentSource::make('admin')],
        );
        $allowedResource = VisibilityClient::query()->findOrFail(101);
        $deniedResource = VisibilityClient::query()->findOrFail(102);
        $member = W::subject();
        $nonMember = SubjectRef::of('user', 2);

        expect($authorizer->decide($panel, AccessRequest::for($member, PermissionKey::of('admin', 'archived-orders.view'))->on(null, $allowedResource))->allowed())->toBeTrue()
            ->and($authorizer->decide($panel, AccessRequest::for($member, PermissionKey::of('admin', 'archived-orders.view'))->on(null, $deniedResource))->allowed())->toBeFalse()
            ->and($visibility->visibleTo($panel, VisibilityClient::query(), $member, 'archived-orders.view')->orderBy('id')->pluck('id')->all())->toBe([101])
            ->and($visibility->visibleTo($panel, VisibilityClient::query(), $nonMember, 'archived-orders.view')->pluck('id')->all())->toBe([]);
    } finally {
        FilamentFixture::reset();
        W::reset();
    }
});

it('filters exact visibility for a filament resource permission definition', function (): void {
    W::seed();
    registerCoverageFilamentPanel();

    try {
        $grant = Grant::of(PermissionPattern::of('admin', 'archived-orders.view'), 'generated', W::scope(1));
        $source = new VisibilitySource(direct: [$grant]);
        [$visibility, $panel, $authorizer] = W::compile($source, extra: [FilamentSource::make('admin')]);
        $allowedResource = VisibilityClient::query()->findOrFail(101);
        $deniedResource = VisibilityClient::query()->findOrFail(102);
        $requests = [
            AccessRequest::for(W::subject(), PermissionKey::of('admin', 'archived-orders.view'))->on(null, $allowedResource),
            AccessRequest::for(W::subject(), PermissionKey::of('admin', 'archived-orders.view'))->on(null, $deniedResource),
        ];
        $batch = $authorizer->decideMany($requests);

        expect($authorizer->decide($panel, $requests[0])->allowed())->toBeTrue()
            ->and($authorizer->decide($panel, $requests[1])->allowed())->toBeFalse()
            ->and($batch->get(0)->allowed())->toBeTrue()
            ->and($batch->get(1)->allowed())->toBeFalse()
            ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'archived-orders.view')->orderBy('id')->pluck('id')->all())->toBe([101]);
    } finally {
        FilamentFixture::reset();
        W::reset();
    }
});
