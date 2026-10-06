<?php

declare(strict_types=1);

use AzGuard\Contracts\Panels\PanelRegistry as PanelRegistryContract;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePanelException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\AdminReplacementPanel;
use AzGuard\Tests\Fixtures\Panels\AnyIdPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\Manager;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\SellerPanel;
use AzGuard\Tests\Fixtures\Panels\SharedPermission;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Sources\StaticSource;
use Illuminate\Support\ServiceProvider;

beforeEach(function (): void {
    FixturePanel::reset();
});

function panelRegistry(): PanelRegistry
{
    return new PanelRegistry(app());
}

it('declares the registry contract of ten methods', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PanelRegistryContract::class))->getMethods(),
    );

    expect($methods)->toEqualCanonicalizing([
        'get', 'find', 'all', 'forModel', 'defaultFor', 'register', 'replace', 'configure', 'configureAll', 'isFrozen',
    ])->and(new PanelRegistry(app()))->toBeInstanceOf(PanelRegistryContract::class);
});

it('rejects a second registration of a panel id, by another class and by the same one', function (): void {
    $registry = panelRegistry();
    $registry->register(AdminPanel::class);

    expect(fn () => $registry->register(AdminReplacementPanel::class))->toThrow(DuplicatePanelException::class, 'replace()')
        ->and(fn () => $registry->register(AdminPanel::class))->toThrow(DuplicatePanelException::class, '"admin"');
});

it('replaces the provider of a panel before the registry is frozen', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Original'));
    AdminReplacementPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Replacement'));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->replace(AdminReplacementPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->label())->toBe('Replacement')
        ->and(array_keys($registry->all()))->toBe(['admin', 'cabinet'])
        ->and(AdminPanel::calls())->toBe(0);
});

it('applies a replacement when the panels are compiled, whatever the order of register and replace', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Original'));
    AdminReplacementPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Replacement'));

    $registry = panelRegistry();
    $registry->replace(AdminReplacementPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->label())->toBe('Replacement')
        ->and(array_keys($registry->all()))->toBe(['cabinet', 'admin'])
        ->and(AdminPanel::calls())->toBe(0)
        ->and(AdminReplacementPanel::calls())->toBe(1);
});

it('lets the last replacement of a panel win', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Original'));
    AdminReplacementPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Replacement'));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->replace(AdminReplacementPanel::class);
    $registry->replace(AdminPanel::class);
    $registry->freeze();

    expect($registry->get('admin')->label())->toBe('Original')
        ->and(AdminReplacementPanel::calls())->toBe(0);
});

it('reports a replacement of a panel nobody registered when the panels are compiled', function (): void {
    $registry = panelRegistry();
    $registry->register(CabinetPanel::class);
    $registry->replace(AdminPanel::class);

    expect(fn () => $registry->freeze())->toThrow(UnknownPanelException::class, 'Registered panels: cabinet')
        ->and($registry->isFrozen())->toBeFalse();
});

it('refuses every change once frozen', function (Closure $change): void {
    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect($registry->isFrozen())->toBeTrue()
        ->and(fn () => $change($registry))->toThrow(RegistryFrozenException::class, 'frozen');
})->with([
    'register' => [fn (PanelRegistry $registry) => $registry->register(CabinetPanel::class)],
    'replace' => [fn (PanelRegistry $registry) => $registry->replace(AdminReplacementPanel::class)],
    'configure' => [fn (PanelRegistry $registry) => $registry->configure('admin', fn () => null)],
    'configureAll' => [fn (PanelRegistry $registry) => $registry->configureAll(fn () => null)],
]);

it('rejects a panel id outside the grammar', function (string $id): void {
    AnyIdPanel::$id = $id;

    expect(fn () => panelRegistry()->register(AnyIdPanel::class))->toThrow(InvalidPanelIdException::class);
})->with(['', 'a.b', '*', 'A', 'a b', 'a:b']);

it('rejects a class that is not a panel provider', function (string $class): void {
    $registry = panelRegistry();

    expect(fn () => $registry->register($class))->toThrow(DefinitionException::class, 'is not a panel provider')
        ->and(fn () => $registry->replace($class))->toThrow(DefinitionException::class, 'is not a panel provider');
})->with([ServiceProvider::class, stdClass::class, 'App\Missing\Panel']);

it('hides panels until they are compiled', function (Closure $read): void {
    $registry = panelRegistry();
    $registry->register(AdminPanel::class);

    expect($registry->isFrozen())->toBeFalse()
        ->and(fn () => $read($registry))->toThrow(DefinitionException::class, 'not compiled yet');
})->with([
    'get' => [fn (PanelRegistry $registry) => $registry->get('admin')],
    'find' => [fn (PanelRegistry $registry) => $registry->find('admin')],
    'all' => [fn (PanelRegistry $registry) => $registry->all()],
    'forModel' => [fn (PanelRegistry $registry) => $registry->forModel(User::class)],
    'defaultFor' => [fn (PanelRegistry $registry) => $registry->defaultFor(User::class)],
    'forPrefix' => [fn (PanelRegistry $registry) => $registry->forPrefix('admin')],
    'forEnum' => [fn (PanelRegistry $registry) => $registry->forEnum(OrderPermission::class)],
    'recipe' => [fn (PanelRegistry $registry) => $registry->recipe('admin')],
    'fingerprint' => [fn (PanelRegistry $registry) => $registry->fingerprint('admin')],
]);

it('reads compiled panels in registration order', function (): void {
    $registry = panelRegistry();
    $registry->register(CabinetPanel::class);
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect(array_keys($registry->all()))->toBe(['cabinet', 'admin'])
        ->and($registry->get('admin')->id())->toBe('admin')
        ->and($registry->find('admin'))->toBe($registry->get('admin'))
        ->and($registry->find('missing'))->toBeNull()
        ->and(fn () => $registry->get('missing'))->toThrow(UnknownPanelException::class, 'Registered panels: cabinet, admin')
        ->and(fn () => $registry->recipe('missing'))->toThrow(UnknownPanelException::class)
        ->and(fn () => $registry->fingerprint('missing'))->toThrow(UnknownPanelException::class);
});

it('freezes once and compiles every provider once', function (): void {
    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->freeze();
    $admin = $registry->get('admin');
    $registry->freeze();

    expect(AdminPanel::calls())->toBe(1)
        ->and($registry->get('admin'))->toBe($admin);
});

it('compiles an empty registry', function (): void {
    $registry = panelRegistry();
    $registry->freeze();

    expect($registry->all())->toBe([])
        ->and($registry->defaultFor(User::class))->toBeNull()
        ->and(fn () => $registry->get('admin'))->toThrow(UnknownPanelException::class, 'Registered panels: none');
});

it('applies configure callbacks as the provider and configureAll callbacks below it, once per panel', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->label('Admin')
        ->permissions([StaticSource::names('clients', 'clients.view', 'clients.update')])
        ->scopes(AssignmentScopePolicy::inherit(ProjectScope::class))
        ->roles([SellerRole::class]));
    $calls = ['configure' => 0, 'all' => []];

    $registry = panelRegistry();
    $registry->configure('admin', function (PanelBuilder $panel) use (&$calls): void {
        $calls['configure']++;
        $panel->roles([AnalystRole::class]);
    });
    $registry->configureAll(function (PanelBuilder $panel) use (&$calls): void {
        $calls['all'][] = true;
        $panel->label('Shared')->roles([RootRole::class]);
    });
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    $admin = $registry->recipe('admin');

    expect($calls)->toBe(['configure' => 1, 'all' => [true, true]])
        ->and($registry->get('admin')->label())->toBe('Admin')
        ->and($registry->get('cabinet')->label())->toBe('Shared')
        ->and($admin->roles())->toBe([SellerRole::class, AnalystRole::class, RootRole::class])
        ->and(array_map(
            static fn (array $record): string => $record['origin']['kind'],
            $admin->layered(PanelRecipe::ROLES),
        ))->toBe(['provider', 'provider', 'configure'])
        ->and($registry->recipe('cabinet')->roles())->toBe([RootRole::class]);
});

it('reports a configure callback for a panel nobody registered when the panels are compiled', function (): void {
    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->configure('cabinet', fn () => null);

    expect(fn () => $registry->freeze())->toThrow(UnknownPanelException::class, '"cabinet"')
        ->and($registry->isFrozen())->toBeFalse()
        ->and(fn () => $registry->all())->toThrow(DefinitionException::class, 'not compiled yet');
});

it('seals the builder of a compiled panel', function (): void {
    $kept = null;
    AdminPanel::describe(function (PanelBuilder $panel) use (&$kept): void {
        $kept = $panel;
    });

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->freeze();

    expect($registry->recipe('admin')->isSealed())->toBeTrue()
        ->and(fn () => $kept->permissions([OrderPermission::class]))->toThrow(RegistryFrozenException::class, 'already compiled')
        ->and($registry->recipe('admin')->enums())->toBe([]);
});

it('lists the panels of a model and of its subclasses', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for([User::class, Seller::class]));
    SellerPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Manager::class));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->register(SellerPanel::class);
    $registry->freeze();

    $ids = static fn (string $model): array => array_map(static fn ($panel): string => $panel->id(), $registry->forModel($model));

    expect($ids(User::class))->toBe(['admin', 'cabinet'])
        ->and($ids(Manager::class))->toBe(['admin', 'cabinet', 'seller'])
        ->and($ids(Seller::class))->toBe(['cabinet'])
        ->and($ids(stdClass::class))->toBe([]);
});

it('picks the default panel of a model', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for([User::class, Seller::class])->default());
    SellerPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Manager::class));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->register(SellerPanel::class);
    $registry->freeze();

    expect($registry->defaultFor(User::class)?->id())->toBe('cabinet')
        ->and($registry->defaultFor(Seller::class)?->id())->toBe('cabinet')
        ->and($registry->defaultFor(stdClass::class))->toBeNull();
});

it('takes the only panel of a model as its default and none when several panels are equal', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for([User::class, Seller::class]));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect($registry->defaultFor(Seller::class)?->id())->toBe('cabinet')
        ->and($registry->defaultFor(User::class))->toBeNull();
});

it('rejects two default panels of one model', function (string $second): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default());
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for([Seller::class, $second])->default());

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);

    expect(fn () => $registry->freeze())->toThrow(DefaultPanelConflictException::class, '"admin" and "cabinet"')
        ->and($registry->isFrozen())->toBeFalse();
})->with(['the same model' => User::class, 'a subclass of the model' => Manager::class]);

it('allows default panels of different models', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default());
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Seller::class)->default());

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    expect($registry->defaultFor(User::class)?->id())->toBe('admin')
        ->and($registry->defaultFor(Seller::class)?->id())->toBe('cabinet');
});

it('keeps a fingerprint for every compiled panel', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->permissions([OrderPermission::class]));

    $first = panelRegistry();
    $first->register(AdminPanel::class);
    $first->register(CabinetPanel::class);
    $first->freeze();

    $second = panelRegistry();
    $second->register(AdminPanel::class);
    $second->freeze();

    expect($first->fingerprint('admin'))->toBe($second->fingerprint('admin'))
        ->and($first->fingerprint('admin'))->not->toBe($first->fingerprint('cabinet'));
});

it('indexes panel prefixes and rejects a prefix shared by two panels', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix('backoffice'));
    SellerPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->resourcePrefix(false));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->register(SellerPanel::class);
    $registry->freeze();

    expect($registry->forPrefix('backoffice')?->id())->toBe('admin')
        ->and($registry->forPrefix('cabinet')?->id())->toBe('cabinet')
        ->and($registry->forPrefix('admin'))->toBeNull()
        ->and($registry->forPrefix('seller'))->toBeNull();
});

it('indexes the permission enums of every panel', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([OrderPermission::class, SharedPermission::class]));
    CabinetPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([SharedPermission::class, SharedPermission::class]));

    $registry = panelRegistry();
    $registry->register(AdminPanel::class);
    $registry->register(CabinetPanel::class);
    $registry->freeze();

    $ids = static fn (string $enum): array => array_map(static fn ($panel): string => $panel->id(), $registry->forEnum($enum));

    expect($ids(OrderPermission::class))->toBe(['admin'])
        ->and($ids(SharedPermission::class))->toBe(['admin', 'cabinet'])
        ->and($ids(InvoicePermission::class))->toBe([]);
});
