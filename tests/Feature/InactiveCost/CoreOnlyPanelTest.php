<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\AuditServiceProvider;
use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\DevTools\DevToolsServiceProvider;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\Internal\ScopeEligibility;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Changes\Roles\RootRole;
use AzGuard\Tests\Fixtures\Changes\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * I13 harness (PLAN1 P1.4), blocking since P3.12: an inactive module costs nothing. A core-only panel (no Audit
 * plugin, scope mode `None`) boots, checks and grants; during that run the container may resolve no
 * service whose type the ownership oracle gives to scopes, audit or devtools (counted through the container
 * `resolving` callback), no query may touch a module table (counted through `DB::listen`), the doctor may not need a
 * module table, and DevTools commands are absent when the application does not run in the console.
 *
 * tests/Fixtures/InactiveCost/allowlist.json is empty since the Audit module owns its table (P3.5): the costs found
 * must equal the list exactly, so any inactive cost fails.
 */

beforeEach(fn () => CrmWorld::seed());

afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/**
 * The CRM panel without ScopesPlugin (mode `None`, the project type declared only so the stand's roles compile, no
 * evaluator) and without the Audit plugin; plain tenant requests.
 */
function coreOnlyPanel(): Panel
{
    CrmWorld::$scopes = false;

    $coreOnly = static function (PanelBuilder $panel): void {
        $panel->roles([RootRole::class, SupportRole::class])->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, [ProjectScope::make()]));
    };

    return CrmWorld::compile($coreOnly, backoffice: $coreOnly, exact: true);
}

function coreOnlyAllows(Panel $panel, int $user): bool
{
    $request = AccessRequest::for(SubjectRef::of('crm.user', $user), PermissionKey::of('crm', 'clients.view_any'))
        ->inTenant(TenantRef::of('crm.organization', 1));

    return app(Authorizer::class)->decide($panel, $request)->allowed();
}

/** @return list<array<string, mixed>> */
function inactiveCostAllowlist(string ...$kinds): array
{
    $entries = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/InactiveCost/allowlist.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter($entries, static fn (array $entry): bool => $kinds === [] || in_array($entry['cost'], $kinds, true)));
}

/**
 * @param  list<array<string, mixed>>  $costs
 * @return list<string> "<cost> <subject>", sorted
 */
function inactiveCostKeys(array $costs): array
{
    $keys = array_values(array_unique(array_map(static fn (array $cost): string => $cost['cost'].' '.$cost['subject'], $costs)));
    sort($keys);

    return $keys;
}

/**
 * The module-owned services and module-table queries of a core-only boot, check and grant.
 *
 * @param  Closure|null  $before  extra work before the measurement starts (what the container already holds)
 * @param  Closure|null  $during  extra work inside the measured window: the non-vacuity tests plant a cost with it
 * @return array{costs: list<array{cost: string, subject: string}>, behavior: array<string, mixed>}
 */
function coreOnlyRun(?Closure $before = null, ?Closure $during = null): array
{
    $ownership = Ownership::fromOracle();
    $resolved = [];
    $queries = [];
    $before?->__invoke();
    app()->resolving(static function (mixed $object) use (&$resolved): void {
        if (is_object($object)) {
            $resolved[$object::class] = true;
        }
    });
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $panel = coreOnlyPanel();
    $before = coreOnlyAllows($panel, 2);
    $granted = ChangeWorld::grant($panel, 'support', 2, null);
    $after = coreOnlyAllows($panel, 2);
    $costs = [];
    $during?->__invoke();

    foreach (array_keys($resolved) as $type) {
        if (in_array($ownership->referenceOwner('reference', $type), Ownership::MODULES, true)) {
            $costs[] = ['cost' => 'module-service', 'subject' => $type];
        }
    }

    // The `resolving` callback does not fire for a singleton the container already holds: its instances count too.
    foreach ((new ReflectionProperty(Container::class, 'instances'))->getValue(app()) as $instance) {
        if (is_object($instance) && in_array($ownership->referenceOwner('reference', $instance::class), Ownership::MODULES, true)) {
            $costs[] = ['cost' => 'module-service', 'subject' => $instance::class];
        }
    }

    foreach (array_keys(Ownership::TABLES) as $table) {
        if (array_filter($queries, static fn (string $sql): bool => str_contains($sql, $table)) !== []) {
            $costs[] = ['cost' => 'module-query', 'subject' => $table];
        }
    }

    return ['costs' => $costs, 'behavior' => ['before' => $before, 'granted' => $granted->status, 'after' => $after, 'queries' => count($queries), 'resolved' => count($resolved)]];
}

/**
 * @param  iterable<object>  $commands
 * @return list<string> the classes of the commands that the oracle gives to DevTools, sorted
 */
function devToolsCommands(iterable $commands): array
{
    $ownership = Ownership::fromOracle();
    $owned = [];

    foreach ($commands as $command) {
        if ($ownership->referenceOwner('reference', $command::class) === 'devtools') {
            $owned[] = $command::class;
        }
    }
    sort($owned);

    return $owned;
}

it('boots, checks and grants a core-only panel', function (): void {
    ['behavior' => $behavior] = coreOnlyRun();

    expect($behavior['before'])->toBeFalse()
        ->and($behavior['granted'])->toBe(ChangeStatus::Applied)
        ->and($behavior['after'])->toBeTrue()
        ->and($behavior['queries'])->toBeGreaterThan(0)
        ->and($behavior['resolved'])->toBeGreaterThan(10);
});

it('I13: the services resolved and the queries run for a core-only panel belong to core', function (): void {
    $found = inactiveCostKeys(coreOnlyRun()['costs']);
    $listed = inactiveCostKeys(inactiveCostAllowlist('module-service', 'module-query'));

    expect(array_values(array_diff($found, $listed)))->toBe([], 'a new inactive cost: do not allowlist it, remove it')
        ->and(array_values(array_diff($listed, $found)))->toBe([], 'a stale entry of tests/Fixtures/InactiveCost/allowlist.json: delete it');
});

it('counts a query to a module table, a module service resolved in the window and one the container already held', function (): void {
    $inWindow = coreOnlyRun(during: static function (): void {
        AuditTable::create();
        CrmWorld::storage()->table('audit_log')->count();
        app()->make(ScopeEligibility::class);
    })['costs'];
    $held = coreOnlyRun(before: static function (): void {
        app()->singleton(ScopeEligibility::class);
        app()->make(ScopeEligibility::class);
    })['costs'];

    expect($inWindow)->toContain(['cost' => 'module-query', 'subject' => 'audit_log'])
        ->and($inWindow)->toContain(['cost' => 'module-service', 'subject' => ScopeEligibility::class])
        ->and($held)->toContain(['cost' => 'module-service', 'subject' => ScopeEligibility::class]);
});

it('I13: the doctor of a core-only database needs no module table', function (): void {
    coreOnlyPanel();
    $storage = CrmWorld::storage();

    foreach (array_keys(Ownership::TABLES) as $table) {
        $storage->connection()->getSchemaBuilder()->dropIfExists($storage->prefix().$table);
    }
    $exit = Artisan::call('azguard:doctor', ['--json' => true]);
    $findings = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $costs = [];

    foreach (array_keys(Ownership::TABLES) as $table) {
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'error' && str_contains(json_encode($finding, JSON_THROW_ON_ERROR), $table)) {
                $costs[] = ['cost' => 'module-table-required', 'subject' => $table];
            }
        }
    }
    $unrelated = array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'error'
        && array_filter(array_keys(Ownership::TABLES), static fn (string $table): bool => str_contains(json_encode($finding, JSON_THROW_ON_ERROR), $table)) === []);

    expect(array_values($unrelated))->toBe([], 'the doctor reports an error that is not about a module table')
        ->and(inactiveCostKeys($costs))->toBe(inactiveCostKeys(inactiveCostAllowlist('module-table-required')))
        ->and($exit === 0 || $costs !== [])->toBeTrue('the doctor fails for a reason other than a module table');
});

it('I13: no DevTools command is registered when the application does not run in the console', function (): void {
    $inConsole = devToolsCommands(array_map(static fn ($command) => $command, app(ConsoleKernel::class)->all()));
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
    // `Artisan::starting()` callbacks are static: those of the console application above would load into this one too.
    ConsoleApplication::forgetBootstrappers();

    try {
        $web = test()->createApplication();
        $running = $web->runningInConsole();
        $outside = devToolsCommands($web->make(ConsoleKernel::class)->all());
    } finally {
        unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
    }
    $listed = array_map(static fn (array $entry): string => $entry['subject'], inactiveCostAllowlist('devtools-command'));
    sort($listed);

    expect($inConsole)->not->toBe([], 'control: the console application has DevTools commands')
        ->and($running)->toBeFalse('control: the second application does not run in the console')
        ->and($outside)->toBe($listed);
});

it('keeps the inactive-cost allowlist empty once Audit owns its table (P3.5)', function (): void {
    expect(inactiveCostAllowlist())->toBe([]);
});

it('I13: with the module roots installed and their providers registered, a core-only panel still costs nothing', function (): void {
    // Control: the modules are really there — their types autoload and the providers of Audit and DevTools are registered.
    $present = [
        class_exists(AuditPlugin::class), class_exists(ScopesPlugin::class),
        app()->getProviders(AuditServiceProvider::class) !== [], app()->getProviders(DevToolsServiceProvider::class) !== [],
    ];
    $run = coreOnlyRun();

    expect($present)->toBe([true, true, true, true])
        ->and($run['behavior']['granted'])->toBe(ChangeStatus::Applied)
        ->and(inactiveCostKeys($run['costs']))->toBe([])
        ->and(Schema::connection(CrmWorld::storage()->connectionName())->hasTable(CrmWorld::storage()->prefix().'audit_log'))->toBeFalse();
});
