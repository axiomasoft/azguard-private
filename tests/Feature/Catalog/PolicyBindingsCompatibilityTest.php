<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Catalog\CatalogCache;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GatePermission;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\PoliciesGate\GateWorld;
use AzGuard\Tests\Fixtures\PoliciesGate\NativePolicy;
use AzGuard\Tests\Fixtures\PoliciesGate\PhpPolicy;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    FixturePanel::reset();
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-bindings-'.bin2hex(random_bytes(8)).'/catalog.php';
    self::$catalogBuildId = 'binding-build';
});

afterEach(function (): void {
    @unlink(self::$catalogCachePath);
    @rmdir(dirname(self::$catalogCachePath));
    Relation::morphMap([], false);
});

it('keeps one typed binding map with a PHP only legacy projection and no fake policy for gate', function (): void {
    Gate::define('beta-access', static fn (): bool => true);
    [,, $registry] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);
    $catalog = $registry->catalog('admin');
    $bindings = $catalog->policyBindings();

    expect(array_keys($bindings))->toEqualCanonicalizing(['beta.access', 'beta.php'])
        ->and($bindings['beta.access']->kind)->toBe('gate')->and($bindings['beta.access']->policy)->toBeNull()
        ->and($bindings['beta.php']->kind)->toBe('php')->and($bindings['beta.php']->policy)->toBe(PhpPolicy::class)
        ->and($catalog->bindings())->toBe(['beta.php' => PhpPolicy::class])
        ->and($catalog->bindingMethod('beta.php'))->toBe('decision')
        ->and(fn () => $catalog->bindingMethod('beta.access'))->toThrow(DefinitionException::class);
});

it('restores typed php and native model bindings and evaluates the native policy after catalog cache boot', function (): void {
    AdminPanel::describe(static function (PanelBuilder $panel): PanelBuilder {
        Gate::policy(GateRecord::class, NativePolicy::class);

        return $panel->for(User::class)->resourcePrefix(false)
            ->permissions([GateWorld::permissions(), GateSource::make()->map(GatePermission::Access, 'native', GateRecord::class)])
            ->policies([PolicyBinding::for(GatePermission::Php, PhpPolicy::class)]);
    });
    $this->bootCatalogPanels([AdminPanel::class]);
    GateWorld::seed();
    $registry = app(PanelRegistry::class);
    $snapshot = $registry->catalog('admin')->snapshot();
    $request = GateWorld::request()->on(null, new GateRecord);
    $live = app(Authorizer::class)->decide($registry->get('admin'), $request);
    expect(StaticSource::$reads)->toBe(['beta' => 1]);
    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require self::$catalogCachePath;
    $scalar = static function (mixed $value) use (&$scalar): bool {
        return is_array($value) ? array_all($value, $scalar) : is_scalar($value) || $value === null;
    };
    expect($scalar($file))->toBeTrue()->and($file['version'])->toBe(CatalogCache::VERSION)
        ->and(CatalogCache::VERSION)->toBe(2);

    $this->bootCatalogPanels([AdminPanel::class]);
    GateWorld::seed();
    $restored = app(PanelRegistry::class);
    $catalog = $restored->catalog('admin');
    $binding = $catalog->policyBindings()['beta.access'];
    $cached = app(Authorizer::class)->decide($restored->get('admin'), GateWorld::request()->on(null, new GateRecord));
    expect(StaticSource::$reads)->toBe([])->and($catalog->snapshot())->toBe($snapshot)
        ->and($binding->kind)->toBe('gate')->and($binding->policy)->toBeNull()->and($binding->method)->toBeNull()
        ->and($binding->ability)->toBe('native')->and($binding->resourceModel)->toBe(GateRecord::class)
        ->and($catalog->bindings())->toBe(['beta.php' => PhpPolicy::class])
        ->and($live->allowed())->toBeTrue()->and($cached->allowed())->toBeTrue()
        ->and($cached->reason)->toBe($live->reason);
});

it('rejects the previous catalog schema rather than restoring untyped policy bindings', function (): void {
    $old = ['version' => 1, 'build_id' => 'binding-build', 'panels' => ['admin' => ['fingerprint' => 'recipe', 'catalog' => ['bindings' => ['beta.php' => PhpPolicy::class]]]]];

    expect(CatalogCache::entry($old, 'binding-build', 'admin', 'recipe'))->toBeNull();
});

it('rebuilds a cached catalog when the native ability in the panel recipe changes', function (): void {
    $describe = static fn (string $ability): Closure => static function (PanelBuilder $panel) use ($ability): PanelBuilder {
        Gate::define('old-access', static fn (): bool => true);
        Gate::define('new-access', static fn (): bool => false);

        return $panel->resourcePrefix(false)->permissions([StaticSource::names('orders', 'orders.view')])
            ->policies([PolicyBinding::gate('orders.view', $ability)]);
    };
    AdminPanel::describe($describe('old-access'));
    $this->bootCatalogPanels([AdminPanel::class]);
    $before = app(PanelRegistry::class)->fingerprint('admin');
    $this->artisan('azguard:catalog:cache')->assertSuccessful();

    AdminPanel::describe($describe('new-access'));
    $this->bootCatalogPanels([AdminPanel::class]);
    $registry = app(PanelRegistry::class);

    expect($registry->catalog('admin')->policyBindings()['orders.view']->ability)->toBe('new-access')
        ->and($registry->fingerprint('admin'))->not->toBe($before)
        ->and(StaticSource::$reads)->toBe(['orders' => 1]);
});

it('detects collisions through the typed map for php gate, gate php and gate gate bindings', function (string $first, string $second): void {
    Gate::define('beta-access', static fn (): bool => true);
    Gate::define('other-access', static fn (): bool => true);
    $bindings = array_map(static fn (string $kind): PolicyBinding => $kind === 'php'
        ? PolicyBinding::for(GatePermission::Access, PhpPolicy::class)
        : PolicyBinding::gate(GatePermission::Access, $kind === 'gate' ? 'beta-access' : 'other-access'), [$first, $second]);

    expect(fn () => GateWorld::compile([], $bindings))->toThrow(DuplicatePolicyBindingException::class);
})->with(['php gate' => ['php', 'gate'], 'gate php' => ['gate', 'php'], 'gate gate' => ['gate', 'other gate']]);

it('requires a binding for a policy only permission while accepting a nullable gate policy class', function (): void {
    expect(fn () => GateWorld::compile([]))->toThrow(InvalidPolicyStructureException::class);
    Gate::define('beta-access', static fn (): bool => true);
    [,, $registry] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);

    expect($registry->catalog('admin')->policyBindings()['beta.access']->policy)->toBeNull();
});
