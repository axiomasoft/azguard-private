<?php

declare(strict_types=1);

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ChangeException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\NumericPermission;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\PlainMarker;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\SellerPanel;
use AzGuard\Tests\Fixtures\Panels\SharedPermission;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('picks the panel named by the caller', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    $resolved = $resolver->resolve(new User, panel: 'cabinet');

    expect($resolved['panel']->id())->toBe('cabinet')
        ->and($resolved['step'])->toBe(PanelResolver::EXPLICIT)
        ->and($resolved['key'])->toBeNull();
});

it('reads the panel from a full name, a panel prefix and an enum of one panel', function (mixed $permission, string $local): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet();
    $current->set($resolver->resolve(panel: 'cabinet')['panel']);

    $resolved = $resolver->resolve(new User, $permission);

    expect($resolved['panel']->id())->toBe('admin')
        ->and($resolved['step'])->toBe(PanelResolver::EXPLICIT)
        ->and($resolved['key']?->full())->toBe('admin:'.$local);
})->with([
    'full name' => ['admin:orders.view', 'orders.view'],
    'full name of an action nobody defined' => ['admin:ghosts.summon', 'ghosts.summon'],
    'panel prefix' => ['admin.orders.view', 'orders.view'],
    'enum attached to one panel' => [OrderPermission::Update, 'orders.update'],
]);

it('gives one key to a local name and to the same name with the prefix of its panel', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    $local = $resolver->resolve(new User, 'orders.view', 'admin')['key'];
    $prefixed = $resolver->resolve(new User, 'admin.orders.view', 'admin')['key'];
    $full = $resolver->resolve(new User, 'admin:orders.view', 'admin')['key'];

    expect($local?->full())->toBe('admin:orders.view')
        ->and($prefixed?->equals($local))->toBeTrue()
        ->and($full?->equals($local))->toBeTrue();
});

it('keeps a full name independent of prefixes', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect($resolver->resolve(null, 'admin:cabinet.orders.view')['key']?->local())->toBe('cabinet.orders.view');
});

it('rejects explicit signals that name different panels and picks none of them', function (mixed $permission, string $panel, string $signal): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet(adminIsDefault: true);
    $current->set($resolver->resolve(panel: 'admin')['panel']);

    expect(fn () => $resolver->resolve(new User, $permission, $panel))
        ->toThrow(ConflictingPanelException::class, $signal)
        ->and($current->get()?->id())->toBe('admin');
})->with([
    'argument against a full name' => ['admin:orders.view', 'cabinet', 'the full name "admin:orders.view" names "admin"'],
    'argument against a prefix' => ['admin.orders.view', 'cabinet', 'the prefix of "admin.orders.view" names "admin"'],
    'argument against an enum of another panel' => [OrderPermission::View, 'cabinet', 'the panel argument "cabinet" names "cabinet"'],
    'enum named first in the message' => [InvoicePermission::View, 'admin', 'the enum '.InvoicePermission::class.' names "cabinet"'],
]);

it('requires an explicit panel for an enum attached to several panels', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet(adminIsDefault: true);
    $current->set($resolver->resolve(panel: 'admin')['panel']);

    expect(fn () => $resolver->resolve(new User, SharedPermission::Export))
        ->toThrow(AmbiguousPanelException::class, '"admin", "cabinet"')
        ->and($resolver->resolve(new User, SharedPermission::Export, 'cabinet')['key']?->full())->toBe('cabinet:reports.export')
        ->and($resolver->resolve(new User, SharedPermission::Export, 'admin')['panel']->id())->toBe('admin');
});

it('reports an enum that no panel attached', function (mixed $permission, ?string $panel): void {
    [$resolver] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->permissions([OrderPermission::class]),
    ]);

    expect(fn () => $resolver->resolve(new User, $permission, $panel))
        ->toThrow(UnknownPermissionException::class, 'is not attached to any panel');
})->with([
    'no panel named' => [InvoicePermission::View, null],
    'a panel named' => [InvoicePermission::View, 'admin'],
    'pure enum' => [PlainMarker::One, 'admin'],
    'int-backed enum' => [NumericPermission::One, null],
]);

it('reports a named panel that is not registered', function (mixed $permission, ?string $panel): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect(fn () => $resolver->resolve(new User, $permission, $panel))->toThrow(UnknownPanelException::class, '"ghost"');
})->with([
    'panel argument' => ['orders.view', 'ghost'],
    'full name' => ['ghost:orders.view', null],
]);

it('rejects a subject the picked panel does not accept', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect(fn () => $resolver->resolve(new Seller, 'admin:orders.view'))
        ->toThrow(SubjectNotAcceptedException::class, Seller::class.' is not a subject of panel "admin"')
        ->and(fn () => $resolver->resolve(new Seller, OrderPermission::View))->toThrow(SubjectNotAcceptedException::class)
        ->and(fn () => $resolver->resolve(new Vendor, panel: 'cabinet'))->toThrow(SubjectNotAcceptedException::class);
});

it('falls back to the panel of the request when nothing names a panel', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet(adminIsDefault: true);
    $current->set($resolver->resolve(panel: 'cabinet')['panel']);

    $resolved = $resolver->resolve(new User, 'invoices.view');

    expect($resolved['panel']->id())->toBe('cabinet')
        ->and($resolved['step'])->toBe(PanelResolver::CURRENT)
        ->and($resolved['key']?->full())->toBe('cabinet:invoices.view')
        ->and($resolver->resolve()['panel']->id())->toBe('cabinet');
});

it('skips the panel of the request when it does not accept the subject', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet();
    $current->set($resolver->resolve(panel: 'cabinet')['panel']);

    $resolved = $resolver->resolve(new Vendor, 'orders.view');

    expect($resolved['panel']->id())->toBe('admin')
        ->and($resolved['step'])->toBe(PanelResolver::MODEL);
});

it('takes the default panel of the model, then its only panel', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet(adminIsDefault: true);

    expect($resolver->resolve(new User, 'orders.view'))->toMatchArray(['step' => PanelResolver::MODEL])
        ->and($resolver->resolve(new User)['panel']->id())->toBe('admin')
        ->and($resolver->resolve(new Seller)['panel']->id())->toBe('cabinet');
});

it('lets the model override its default panel', function (): void {
    [$resolver] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Vendor::class)->default(),
        CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Vendor::class),
        SellerPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(Seller::class),
    ]);
    $vendor = new Vendor;

    expect($resolver->resolve($vendor)['panel']->id())->toBe('admin');

    $vendor->defaultPanel = 'cabinet';
    expect($resolver->resolve($vendor)['panel']->id())->toBe('cabinet');

    $vendor->defaultPanel = 'seller';
    expect($resolver->resolve($vendor)['panel']->id())->toBe('admin');

    $vendor->defaultPanel = 'ghost';
    expect($resolver->resolve($vendor)['panel']->id())->toBe('admin');
});

it('throws instead of guessing when no panel can be resolved', function (Closure $subject, string $hint): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect(fn () => $resolver->resolve($subject(), 'orders.view'))->toThrow(PanelNotResolvedException::class, $hint);
})->with([
    'a model of two equal panels' => [fn () => new User, 'Panels that accept '.User::class.': "admin", "cabinet"'],
    'no subject' => [fn () => null, 'There is no subject'],
    'a reference without a morph alias' => [fn () => SubjectRef::of('user', 7), 'No panel accepts subject "user:7"'],
]);

it('resolves a subject reference through the morph map', function (): void {
    Relation::morphMap(['seller' => Seller::class, 'user' => User::class]);
    [$resolver] = PanelWorld::adminAndCabinet();

    expect($resolver->resolve(SubjectRef::of('seller', 7), 'invoices.view')['panel']->id())->toBe('cabinet')
        ->and($resolver->resolve(SubjectRef::of('user', 7), 'admin:orders.view')['panel']->id())->toBe('admin')
        ->and(fn () => $resolver->resolve(SubjectRef::of('seller', 7), panel: 'admin'))->toThrow(SubjectNotAcceptedException::class, 'subject "seller:7"');
});

it('rejects a name that is not a permission key of the picked panel', function (string $name): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect(fn () => $resolver->resolve(new User, $name, 'admin'))->toThrow(InvalidPermissionKeyException::class);
})->with(['view', 'admin.orders', 'orders.*', 'Orders.View']);

it('tells which panel owns a gate ability without picking a panel for foreign ones', function (string $ability, ?string $owner): void {
    [$resolver] = PanelWorld::adminAndCabinet();

    expect($resolver->owner($ability, new Seller)?->id())->toBe($owner);
})->with([
    'a single word belongs to Laravel' => ['view', null],
    'full name of a registered panel' => ['admin:orders.view', 'admin'],
    'full name with an unknown action' => ['admin:ghosts.summon', 'admin'],
    'full name of an unknown panel' => ['ghost:orders.view', null],
    'registered prefix' => ['admin.orders.view', 'admin'],
    'dotted name: the panel of the model is the candidate' => ['invoices.view', 'cabinet'],
]);

it('owns no dotted ability when no panel is a candidate', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet();

    expect($resolver->owner('orders.view', new User))->toBeNull()
        ->and($resolver->owner('orders.view'))->toBeNull();

    $current->set($resolver->resolve(panel: 'admin')['panel']);

    expect($resolver->owner('orders.view', new User)?->id())->toBe('admin')
        ->and($resolver->owner('orders.view', new Seller)?->id())->toBe('cabinet');
});

it('cannot resolve before the panels are compiled', function (): void {
    $resolver = new PanelResolver(new PanelRegistry(app()), new CurrentPanel);

    expect(fn () => $resolver->resolve(new User, 'orders.view'))->toThrow(DefinitionException::class, 'not compiled yet')
        ->and(fn () => $resolver->owner('admin.orders.view'))->toThrow(DefinitionException::class, 'not compiled yet');
});

it('gives every selection error its parent and stable code', function (string $class, string $parent, string $code): void {
    $exception = new $class('message');

    expect(get_parent_class($exception))->toBe($parent)
        ->and((new ReflectionClass($class))->isFinal())->toBeTrue()
        ->and($exception->code())->toBe($code);
})->with([
    [PanelNotResolvedException::class, DefinitionException::class, 'panel_not_resolved'],
    [ConflictingPanelException::class, DefinitionException::class, 'conflicting_panel'],
    [AmbiguousPanelException::class, DefinitionException::class, 'ambiguous_panel'],
    [PrefixConflictException::class, DefinitionException::class, 'prefix_conflict'],
    [UnknownPermissionException::class, ChangeException::class, 'unknown_permission'],
]);
