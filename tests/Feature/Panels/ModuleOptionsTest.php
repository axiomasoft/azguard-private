<?php

declare(strict_types=1);

use AzGuard\Audit\AuditOptions;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelFingerprint;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Schema\Field;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\Options\HoldingOptions;
use AzGuard\Tests\Fixtures\Panels\Options\LimitOptions;
use AzGuard\Tests\Fixtures\Panels\Options\MutableOptions;
use AzGuard\Tests\Fixtures\Panels\Options\OptionsPlugin;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Http\Request;

it('reads the options a panel was given by class, and null for a class it was not given', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(25, 'admin'))]);
    $options = $registry->get('admin')->options(LimitOptions::class);

    expect($options)->toEqual(new LimitOptions(25, 'admin'))
        ->and($registry->get('admin')->options(AuditOptions::class))->toBeNull();
});

it('keeps the options of one panel away from another panel and gives each its own fingerprint', function (): void {
    $fingerprints = static fn (int $adminLimit, int $cabinetLimit): array => (static function (PanelRegistry $registry): array {
        return [$registry->fingerprint('admin'), $registry->fingerprint('cabinet')];
    })(PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions($adminLimit)),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions($cabinetLimit)),
    ])[2]);
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(1)),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(2)),
    ]);

    expect($registry->get('admin')->options(LimitOptions::class)?->limit)->toBe(1)
        ->and($registry->get('cabinet')->options(LimitOptions::class)?->limit)->toBe(2)
        ->and($fingerprints(1, 1)[0])->not->toBe($fingerprints(2, 1)[0])
        ->and($fingerprints(1, 1)[1])->toBe($fingerprints(2, 1)[1])
        ->and($fingerprints(1, 1)[0])->toBe($fingerprints(1, 2)[0]);
});

it('leaves the fingerprint metadata of a panel without options as it was', function (): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel,
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions),
    ]);

    expect(PanelFingerprint::metadata($registry->get('admin'), $registry->recipe('admin')))->not->toHaveKey('options')
        ->and(PanelFingerprint::metadata($registry->get('cabinet'), $registry->recipe('cabinet')))->toHaveKey('options')
        ->and($registry->get('admin')->optionObjects())->toBe([]);
});

it('refuses an options object that is not readonly', function (): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new MutableOptions)]))
        ->toThrow(DefinitionException::class, 'options() expects a readonly object');
});

it('refuses a second options object of one class from one layer', function (): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(1))->options(new LimitOptions(2))]))
        ->toThrow(DefinitionException::class, 'one object of '.LimitOptions::class.' per layer');
});

it('refuses options that hold a live model, query, request, container or a closure bound to an object', function (Closure $held): void {
    expect(fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new HoldingOptions($held()))]))
        ->toThrow(DefinitionException::class, 'options() '.HoldingOptions::class);
})->with([
    'a model' => [fn () => new User],
    'a query builder' => [fn () => User::query()],
    'a request' => [fn () => Request::create('/')],
    'the container' => [fn () => app()],
    'a nested model' => [fn () => ['inner' => [new User]]],
    'a bound closure' => [fn () => (fn (): int => 1)->bindTo(new stdClass)],
]);

it('accepts options that hold scalars, enums, arrays and static closures', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->options(new HoldingOptions(['id' => 1, 'enabled' => true, 'rule' => static fn (): int => 2]))]);

    expect($registry->get('admin')->options(HoldingOptions::class))->toBeInstanceOf(HoldingOptions::class);
});

it('takes the provider over a plugin over configure for one class', function (): void {
    $registry = new PanelRegistry(app());
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(1, 'configure')));
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([new OptionsPlugin('acme/limit', 2)]));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(3, 'provider'))->plugins([new OptionsPlugin('acme/limit', 2)]));
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->options(LimitOptions::class)?->limit)->toBe(2)
        ->and($registry->get('cabinet')->options(LimitOptions::class)?->limit)->toBe(3);
});

it('lets the configure layer supply what nobody above it sets', function (): void {
    $registry = new PanelRegistry(app());
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(1, 'configure')));
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel);
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->options(LimitOptions::class))->toEqual(new LimitOptions(1, 'configure'));
});

it('conflicts two plugins that give different options of one class and accepts equal ones', function (): void {
    $compile = static fn (int $second) => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->plugins([new OptionsPlugin('acme/a', 5), new OptionsPlugin('acme/b', $second)])]);

    expect($compile(5)[2]->get('admin')->options(LimitOptions::class)?->limit)->toBe(5)
        ->and(fn () => $compile(6))->toThrow(PluginConflictException::class, 'options '.LimitOptions::class);
});

it('has the audit plugin contribute AuditOptions and no static reader of its retention', function (): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([AuditPlugin::make(retentionDays: 30)]),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([AuditPlugin::make()]),
    ]);

    expect($registry->get('admin')->options(AuditOptions::class)?->retentionDays)->toBe(30)
        ->and($registry->get('cabinet')->options(AuditOptions::class)?->retentionDays)->toBe(90)
        ->and(array_filter(get_class_methods(AuditPlugin::class), static fn (string $method): bool => (new ReflectionMethod(AuditPlugin::class, $method))->isStatic()))->toBe(['make']);
});

it('records options in the recipe under the options setting with their layer', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->options(new LimitOptions(4))]);
    $settings = array_column(array_filter($registry->recipe('admin')->records(), static fn (array $record): bool => $record['setting'] === PanelRecipe::OPTIONS), 'origin');

    expect($settings)->toBe([PanelRecipe::provider()]);
});

it('exposes the id the builder describes', function (): void {
    $seen = null;
    PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use (&$seen): PanelBuilder {
        $seen = $panel->panelId();

        return $panel;
    }]);

    expect($seen)->toBe('admin');
});

it('marks the raw compiled lists of a panel internal', function (string $method): void {
    $doc = (string) (new ReflectionMethod(Panel::class, $method))->getDocComment();

    expect($doc)->toContain('@internal');
})->with(['changing', 'before', 'after', 'restrictions', 'grantConditions', 'doctorChecks', 'attachedSources', 'scopes', 'scopeDefinition', 'scopeDefinitions', 'scopeResolvers', 'tenantResolvers', 'resourceScopes']);

it('rejects a grant field in the reserved azg_ namespace', function (string $name): void {
    expect(fn () => Field::string($name))->toThrow(DefinitionException::class, 'reserved grant field name');
})->with(['azg_note', 'azg_', 'tenant_x', 'subject_x']);
