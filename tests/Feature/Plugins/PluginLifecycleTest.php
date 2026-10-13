<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Panels\Internal\PanelRecipe;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Contracts\Broken\ForeignPlugins;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\ArraySource;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixedResourceScopeResolver;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\Manager;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use AzGuard\Tests\Fixtures\Plugins\Account;
use AzGuard\Tests\Fixtures\Plugins\AuditFreeze;
use AzGuard\Tests\Fixtures\Plugins\AuditTrailPlugin;
use AzGuard\Tests\Fixtures\Plugins\CrmAccessPlugin;
use AzGuard\Tests\Fixtures\Plugins\CrmModels;
use AzGuard\Tests\Fixtures\Plugins\LateChangePlugin;
use AzGuard\Tests\Fixtures\Plugins\ProbePlugin;
use AzGuard\Tests\Fixtures\Plugins\RecordChange;
use AzGuard\Tests\Fixtures\Plugins\ReportsPlugin;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
    ProbePlugin::$log = [];
});

it('registers the plugins of every panel in attachment order and never calls a boot() a plugin declares', function (): void {
    $registry = new PanelRegistry(app());

    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ProbePlugin::make('acme/a'),
        ProbePlugin::make('acme/b'),
    ]));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/b')]));

    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect(ProbePlugin::$log)->toBe(['register:admin:acme/a', 'register:admin:acme/b', 'register:cabinet:acme/b'])
        ->and($registry->isFrozen())->toBeTrue()
        ->and($registry->get('admin')->pluginIds())->toBe(['acme/a', 'acme/b'])
        ->and($registry->get('cabinet')->pluginIds())->toBe(['acme/b']);
});

it('compiles a panel without plugins', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (): null => null]);

    expect($registry->get('admin')->pluginIds())->toBe([]);
});

it('registers provider plugins, then plugins for all panels, then plugins attached by plugins until none is left', function (): void {
    $registry = new PanelRegistry(app());
    $attach = static fn (string ...$ids): Closure => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins(
        array_map(static fn (string $id): ProbePlugin => ProbePlugin::make($id), $ids),
    );

    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/global')]));
    $registry->configure('admin', static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/module')]));
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ProbePlugin::make('acme/first', register: static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
            ProbePlugin::make('acme/nested', register: $attach('acme/deep')),
        ])),
    ])->plugins([ProbePlugin::make('acme/second', register: $attach('acme/nested-of-second'))]));

    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->pluginIds())->toBe([
        'acme/first', 'acme/second', 'acme/module', 'acme/global', 'acme/nested', 'acme/nested-of-second', 'acme/deep',
    ]);
});

it('writes what a plugin adds with the origin of that plugin', function (): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->restrictions([RecordingRestriction::class])
            ->plugins([ProbePlugin::make('acme/first'), AuditTrailPlugin::make()]),
    ]);
    $recipe = $registry->recipe('admin');
    $origin = ['kind' => 'plugin', 'plugin' => 'acme/audit-trail', 'order' => 2];
    $written = array_values(array_filter($recipe->records(), static fn (array $record): bool => $record['origin']['kind'] === 'plugin'));

    expect(array_column($written, 'setting'))->toBe([PanelRecipe::PERMISSIONS, PanelRecipe::RESTRICTIONS, PanelRecipe::CHANGING])
        ->and(array_column($written, 'origin'))->toBe([$origin, $origin, $origin])
        ->and($recipe->sources()[0])->toBeInstanceOf(ArraySource::class)
        ->and($recipe->items(PanelRecipe::RESTRICTIONS))->toBe([RecordingRestriction::class, AuditFreeze::class])
        ->and($recipe->items(PanelRecipe::CHANGING))->toBe([RecordChange::class])
        ->and($recipe->isSealed())->toBeTrue();
});

it('lets a plugin with a typed factory describe subjects and resource scopes', function (): void {
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([CrmAccessPlugin::make(
            models: new CrmModels(subject: Account::class, organization: Vendor::class, project: Manager::class, client: Seller::class),
            clientScope: FixedResourceScopeResolver::class,
        )]),
    ]);

    expect($registry->get('admin')->subjectModels())->toBe([Account::class])
        ->and($registry->recipe('admin')->subjects())->toBe([['model' => Account::class, 'guard' => 'web', 'directory' => null]])
        ->and($registry->recipe('admin')->items(PanelRecipe::RESOURCE_SCOPES))
        ->toBe([['resource' => Seller::class, 'resolver' => FixedResourceScopeResolver::class]]);
});

it('keeps plugins for all panels off a panel that names them in withoutPlugins()', function (): void {
    $registry = new PanelRegistry(app());
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ProbePlugin::make('acme/global'), ProbePlugin::make('acme/other'),
    ]));
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->withoutPlugins(['acme/global', 'acme/unknown']));

    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->pluginIds())->toBe(['acme/other'])
        ->and($registry->get('cabinet')->pluginIds())->toBe(['acme/global', 'acme/other'])
        ->and(ProbePlugin::$log)->not->toContain('register:admin:acme/global');
});

it('does not let a plugin detach another plugin', function (): void {
    $compile = static fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
            ProbePlugin::make('acme/a', register: static fn (PanelBuilder $panel): PanelBuilder => $panel->withoutPlugins(['acme/b'])),
        ]),
    ]);

    expect($compile)->toThrow(DefinitionException::class, 'a plugin cannot detach another plugin');
});

it('attaches plugins in any position and tells each plugin its panel, its id and the build', function (): void {
    $contexts = [];
    $register = static function (PanelBuilder $panel, PluginContext $context) use (&$contexts): void {
        $contexts[] = $context;
    };

    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
            ReportsPlugin::class,
            ProbePlugin::make('acme/probe', register: $register),
            AuditTrailPlugin::make(),
        ]),
    ]);

    expect($registry->get('admin')->pluginIds())->toBe(['acme/reports', 'acme/probe', 'acme/audit-trail'])
        ->and($contexts[0]->pluginId())->toBe('acme/probe')
        ->and($contexts[0]->panelId())->toBe('admin')
        ->and($registry->recipe('admin')->items(PanelRecipe::DOCTOR_CHECKS))->not->toBe([])
        ->and((new ReflectionClass(PluginContext::class))->hasMethod('dependencies'))->toBeFalse();
});

it('rejects two plugins with one id on a panel', function (Closure $describe): void {
    $registry = new PanelRegistry(app());
    $registry->configureAll(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/global')]));
    AdminPanel::describe($describe);
    $registry->register(AdminPanel::class);

    try {
        $registry->freeze();
        $this->fail('The panel compiled with two plugins of one id.');
    } catch (PluginConflictException $e) {
        expect($e->code())->toBe('plugin_conflict')
            ->and($e->getMessage())->toContain('Panel "admin" has two plugins with the id')
            ->and($registry->isFrozen())->toBeFalse();
    }
})->with([
    'two objects' => [static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/a'), ProbePlugin::make('acme/a')])],
    'one object twice' => [static function (PanelBuilder $panel): void {
        $plugin = ProbePlugin::make('acme/a');
        $panel->plugins([$plugin])->plugins([$plugin]);
    }],
    'a class twice' => [static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([AuditTrailPlugin::make(), ReportsPlugin::class, ReportsPlugin::class])],
    'the provider and configure for all panels' => [static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/global')])],
    'a plugin attaches an attached plugin' => [static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ProbePlugin::make('acme/a', register: static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/a')])),
    ])],
]);

it('accepts a plugin id of the documented form only', function (string $id, bool $valid): void {
    $compile = static fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make($id)]),
    ]);

    if ($valid) {
        expect($compile()[2]->get('admin')->pluginIds())->toBe([$id]);
    } else {
        expect($compile)->toThrow(DefinitionException::class, 'plugin id');
    }
})->with([
    'a name' => ['audit', true],
    'vendor and name' => ['acme/audit-trail', true],
    'dots, underscores and digits' => ['a.b_c-d/e1.f_g', true],
    '128 bytes' => [str_repeat('a', 128), true],
    '129 bytes' => [str_repeat('a', 129), false],
    'empty' => ['', false],
    'upper case' => ['Acme/Audit', false],
    'three parts' => ['acme/audit/trail', false],
    'no vendor before the slash' => ['/audit', false],
    'no name after the slash' => ['acme/', false],
    'a space' => ['acme audit', false],
    'a leading dash' => ['-acme', false],
    'a name that starts with a dot' => ['acme/.audit', false],
    'a trailing line break' => ["acme/audit\n", false],
    'a colon' => ['acme:audit', false],
]);

it('rejects a class the container does not resolve to a plugin', function (): void {
    app()->bind(ReportsPlugin::class, static fn (): stdClass => new stdClass);

    $compile = static fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ReportsPlugin::class]),
    ]);

    expect($compile)->toThrow(DefinitionException::class, ReportsPlugin::class.' to stdClass, which is not a plugin');
});

it('compiles a plugin that still declares boot() and never calls it', function (): void {
    LateChangePlugin::$boots = 0;
    $registry = new PanelRegistry(app());
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Back office')->plugins([new LateChangePlugin]));
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect(LateChangePlugin::$boots)->toBe(0)
        ->and($registry->isFrozen())->toBeTrue()
        ->and($registry->get('admin')->label())->toBe('Back office')
        ->and($registry->recipe('admin')->layered(PanelRecipe::LABEL))->toHaveCount(1);
});

it('makes the plugins and their order part of the panel fingerprint', function (): void {
    $fingerprint = static fn (string ...$ids): string => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins(
            array_map(static fn (string $id): ProbePlugin => ProbePlugin::make($id), $ids),
        ),
    ])[2]->fingerprint('admin');

    expect($fingerprint('acme/a', 'acme/b'))->toBe($fingerprint('acme/a', 'acme/b'))
        ->and($fingerprint('acme/a', 'acme/b'))->not->toBe($fingerprint('acme/b', 'acme/a'))
        ->and($fingerprint('acme/a'))->not->toBe($fingerprint())
        ->and($fingerprint('acme/a'))->not->toBe($fingerprint('acme/b'));
});

it('tells plugins the configured build id while they register', function (): void {
    config(['azguard.catalog.build_id' => 'release-42']);
    app()->forgetInstance(AzGuardConfig::class);
    $seen = [];
    $plugin = ProbePlugin::make(
        'acme/a',
        register: static function (PanelBuilder $panel, PluginContext $context) use (&$seen): void {
            $seen[] = $context->buildId();
        },
    );

    PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin])]);

    expect($seen)->toBe(['release-42']);
});

it('derives a stable build id from the registered panel providers when none is configured', function (): void {
    $buildId = static function (array $panels): string {
        $seen = null;
        $plugin = ProbePlugin::make('acme/a', register: static function (PanelBuilder $panel, PluginContext $context) use (&$seen): void {
            $seen = $context->buildId();
        });

        PanelWorld::compile(array_fill_keys($panels, static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin])));

        return (string) $seen;
    };

    $one = $buildId([AdminPanel::class]);

    expect($one)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($buildId([AdminPanel::class]))->toBe($one)
        ->and($buildId([AdminPanel::class, CabinetPanel::class]))->not->toBe($one)
        ->and($buildId([CabinetPanel::class, AdminPanel::class]))->toBe($buildId([AdminPanel::class, CabinetPanel::class]))
        ->and(app(AzGuardConfig::class)->buildId([AdminPanel::class]))->toBe($one);
});

it('registers the plugins when the application boots and calls no boot()', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ProbePlugin::make('acme/a'), ReportsPlugin::class, AuditTrailPlugin::make()]));

    $this->bootPanels(['providers' => [AdminPanel::class]]);

    expect(app(PanelRegistry::class)->get('admin')->pluginIds())->toBe(['acme/a', 'acme/reports', 'acme/audit-trail'])
        ->and(ProbePlugin::$log)->toBe(['register:admin:acme/a']);
});

it('rejects the reserved azguard/ id prefix on a plugin class outside the package', function (): void {
    $plugin = ForeignPlugins::reservedId('azguard/own');

    $compile = static fn () => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([$plugin])]);

    expect($compile)->toThrow(PluginConflictException::class, 'the prefix "azguard/" is reserved');
});

it('accepts the azguard/ id prefix on a plugin class of the package and other prefixes outside it', function (): void {
    $foreign = ForeignPlugins::reservedId('acme/own');

    // The journal participant of the audit plugin needs the database writer.
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([DatabaseSource::make()])->plugins([
        AuditPlugin::make(), $foreign, ProbePlugin::make('azguard/probe'),
    ])]);

    expect($registry->get('admin')->pluginIds())->toBe(['azguard/audit', 'acme/own', 'azguard/probe']);
});
