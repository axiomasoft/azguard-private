<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\UnknownSourceException;
use AzGuard\Exceptions\WriterConflictException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\SourceManager;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\BootsPanels;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Sources\LdapSource;
use AzGuard\Tests\Fixtures\Sources\LedgerSource;
use AzGuard\Tests\Fixtures\Sources\RegistersSources;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use AzGuard\Tests\Fixtures\Sources\WriterSource;
use Illuminate\Contracts\Foundation\Application;

uses(BootsPanels::class);

beforeEach(function (): void {
    FixturePanel::reset();
    RegistersSources::reset();
});

it('V77: builds ldap from the attribute with the source configuration, separately for each panel', function (): void {
    RegistersSources::$classes = [LdapSource::class];
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['ldap']));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['ldap']));

    $this->bootPanels(
        ['providers' => [AdminPanel::class, CabinetPanel::class]],
        [RegistersSources::class],
        ['azguard.sources' => ['ldap' => ['group_attribute' => 'memberOf']]],
    );

    expect(LdapSource::$made)->toHaveCount(2)
        ->and(LdapSource::$made[0])->not->toBe(LdapSource::$made[1])
        ->and(LdapSource::$made[0]->config())->toBe(['group_attribute' => 'memberOf'])
        ->and(array_map(static fn ($description) => $description->id, app(PanelRegistry::class)->get('admin')->sources()))->toBe(['folder', 'ldap'])
        ->and(app(PanelRegistry::class)->get('admin')->isWritable())->toBeFalse()
        ->and(app(PanelRegistry::class)->get('admin')->writer())->toBeNull();
});

it('V77: builds ldap from extend() and passes the same configuration', function (): void {
    RegistersSources::$extensions = [
        'ldap' => static fn (Application $app, array $config): LdapSource => new LdapSource($config),
    ];
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['ldap']));

    $this->bootPanels(
        ['providers' => [AdminPanel::class]],
        [RegistersSources::class],
        ['azguard.sources' => ['ldap' => ['group_attribute' => 'memberOf']]],
    );

    expect(LdapSource::$made)->toHaveCount(1)
        ->and(LdapSource::$made[0]->config())->toBe(['group_attribute' => 'memberOf'])
        ->and(AzGuard::sources())->toBe(app(SourceManager::class));
});

it('V77: reports an unknown source name, two writers and a repeated id', function (): void {
    app(SourceManager::class)->register(LdapSource::class);

    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['nope']),
    ]))->toThrow(UnknownSourceException::class, 'names the source "nope"')
        ->and(fn () => PanelWorld::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
                new WriterSource('database'), new WriterSource('audit'),
            ]),
        ]))->toThrow(WriterConflictException::class, '"database"')
        ->and(fn () => PanelWorld::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
                new WriterSource('database'), new WriterSource('audit'),
            ]),
        ]))->toThrow(WriterConflictException::class, '"audit"')
        ->and(fn () => PanelWorld::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
                StaticSource::names('ldap', 'posts.view'), 'ldap',
            ]),
        ]))->toThrow(DefinitionException::class, 'id "ldap"');
});

it('V102: builds a named writer again for each panel and after the scope is forgotten', function (): void {
    RegistersSources::$classes = [LedgerSource::class];
    RegistersSources::$scopedClock = true;
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['ledger']));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions(['ledger']));

    $this->bootPanels(
        ['providers' => [AdminPanel::class, CabinetPanel::class]],
        [RegistersSources::class],
    );

    $registry = app(PanelRegistry::class);
    $admin = $registry->get('admin')->writer();
    $cabinet = $registry->get('cabinet')->writer();

    expect($admin)->not->toBeNull()
        ->and($cabinet)->not->toBeNull()
        ->and($admin)->not->toBe($cabinet)
        ->and($registry->get('admin')->writer())->toBe($admin)
        ->and($registry->get('cabinet')->writer())->toBe($cabinet);

    $token = $admin->clock->token;
    app()->forgetScopedInstances();

    $fresh = app(PanelRegistry::class)->get('admin')->writer();

    expect($fresh)->not->toBe($admin)
        ->and($fresh?->clock->token)->not->toBe($token);
});

it('reuses an object writer across scopes', function (): void {
    $writer = new WriterSource('database');
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([$writer]));

    $this->bootPanels(['providers' => [AdminPanel::class]]);

    expect(app(PanelRegistry::class)->get('admin')->writer())->toBe($writer)
        ->and(app(PanelRegistry::class)->get('admin')->isWritable())->toBeTrue();

    app()->forgetScopedInstances();

    expect(app(PanelRegistry::class)->get('admin')->writer())->toBe($writer);
});

it('V107: keeps enums out of the source list and preserves the order of the rest', function (): void {
    app(SourceManager::class)->register(LdapSource::class);
    $static = StaticSource::names('app', 'posts.view');

    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
            OrderPermission::class, $static, 'ldap',
        ]),
    ]);

    $panel = $registry->get('admin');

    expect(array_map(static fn ($description) => $description->id, $panel->sources()))->toBe(['folder', 'app', 'ldap'])
        ->and(array_map(static fn ($description) => $description->class, $panel->sources()))
        ->toBe([FolderSource::class, StaticSource::class, LdapSource::class])
        ->and($registry->forEnum(OrderPermission::class))->toHaveCount(1);
});

it('P12: a different source order keeps the catalog and the ids and changes the fingerprint', function (): void {
    app(SourceManager::class)->register(LdapSource::class);
    $compile = static fn (array $sources): array => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions($sources),
    ]);

    [, , $first] = $compile([StaticSource::names('app', 'posts.view'), 'ldap']);
    [, , $second] = $compile(['ldap', StaticSource::names('app', 'posts.view')]);

    expect($first->catalog('admin')->all())->toHaveKeys(['posts.view', 'ldap.sync'])
        ->and($second->catalog('admin')->all())->toHaveKeys(['posts.view', 'ldap.sync'])
        ->and(array_map(static fn ($description) => $description->id, $first->get('admin')->sources()))
        ->toEqualCanonicalizing(array_map(static fn ($description) => $description->id, $second->get('admin')->sources()))
        ->and($first->fingerprint('admin'))->not->toBe($second->fingerprint('admin'));
});
