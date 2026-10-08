<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Unguarded\PlainOrderResource;
use AzGuard\Tests\Fixtures\Filament\Unguarded\PlainPage;
use AzGuard\Tests\Fixtures\Filament\Unguarded\PlainWidget;
use AzGuard\Tests\Fixtures\Filament\Unguarded\QueryOrderResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

/*
 * V102: two resources of one model are decided by their own keys; nothing is guessed from the model. A Filament panel
 * that enforces refuses a resource, page or widget that AzGuard does not decide. The Gate::before hook of the package
 * only refuses, and only inside such a panel.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
});

it('V102 opens the resource whose permission is granted and keeps the other resource of the model closed', function (): void {
    $member = GateWorld::grant(['orders.view_any']);
    $this->actingAs($member);

    $this->get('/admin/orders')->assertOk();
    $this->get('/admin/archived-orders')->assertForbidden();

    GateWorld::grant(['archived-orders.view_any']);
    $this->get('/admin/archived-orders')->assertOk();
});

it('V102 decides every ability of a resource by its own key, with the record as the resource of the decision', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete']));

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(OrderResource::canView(Order::query()->findOrFail(1)))->toBeTrue()
        ->and(OrderResource::canView(Order::query()->findOrFail(3)))->toBeFalse()
        ->and(OrderResource::canDelete(Order::query()->findOrFail(1)))->toBeTrue()
        ->and(OrderResource::canDelete(Order::query()->findOrFail(4)))->toBeFalse()
        ->and(OrderResource::canEdit(Order::query()->findOrFail(1)))->toBeFalse()
        ->and(ArchivedOrderResource::canViewAny())->toBeFalse()
        ->and(ArchivedOrderResource::canView(Order::query()->findOrFail(1)))->toBeFalse();
});

it('V102 refuses the model check of Filament in the guarded panel: the resource is never guessed from the model', function (): void {
    $member = GateWorld::grant(['orders.view_any', 'archived-orders.view_any']);
    GateWorld::serve($member);

    expect(Gate::forUser($member)->check('viewAny', Order::class))->toBeFalse()
        ->and(Gate::forUser($member)->check('viewAny', Order::query()->findOrFail(1)))->toBeFalse();
});

it('V102 refuses the ambiguous model ability through the Gate adapter when FilamentSource binds both resources to the model', function (): void {
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    FilamentFixture::$abilities = ['view_any', 'view'];
    FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
    FilamentFixture::$guardPolicies = [];
    FilamentFixture::$pages = [];
    FilamentFixture::$widgets = [];
    $this->bootFilament();
    $member = GateWorld::grant(['orders.view_any']);
    GateWorld::serve($member);

    $response = Gate::forUser($member)->inspect('viewAny', Order::class);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('gate_error')
        ->and(OrderResource::canViewAny())->toBeTrue()
        ->and(ArchivedOrderResource::canViewAny())->toBeFalse();
});

it('refuses a missing user and an unknown ability of a resource while the panel enforces', function (): void {
    Filament::setCurrentPanel('admin');

    expect(OrderResource::canViewAny())->toBeFalse()
        ->and(FilamentGate::resource(OrderResource::class, 'viewAny')->code())->toBe('azguard.filament.subject');

    GateWorld::serve(GateWorld::grant(['orders.view_any']));
    expect(FilamentGate::resource(OrderResource::class, 'publish')->code())->toBe('azguard.filament.definition');
});

it('leaves an unknown ability to Filament and still decides a known one when the panel does not enforce', function (): void {
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(User::query()->findOrFail(1));

    expect(OrderResource::canViewAny())->toBeFalse()
        ->and(OrderResource::can('publish'))->toBeTrue();
});

it('leaves a resource of a Filament panel without the plugin to Filament', function (): void {
    GateWorld::serve(User::query()->findOrFail(2), 'plain');

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(FilamentGate::before(User::query()->findOrFail(2), 'viewAny', [Order::class]))->toBeNull()
        ->and(OrderResource::getEloquentQuery()->count())->toBe(4);
});

it('answers only false or null from Gate::before, and false only for an ability of Filament on a model without a policy', function (): void {
    $member = User::query()->findOrFail(1);
    GateWorld::serve($member);

    expect(FilamentGate::before($member, 'viewAny', [Order::class]))->toBeFalse()
        ->and(FilamentGate::before($member, 'forceDeleteAny', [Order::query()->findOrFail(1)]))->toBeFalse()
        ->and(FilamentGate::before($member, 'publish', [Order::class]))->toBeNull()
        ->and(FilamentGate::before($member, 'viewAny', []))->toBeNull();

    Gate::define('viewAny', static fn (): bool => true);
    expect(FilamentGate::before($member, 'viewAny', [Order::class]))->toBeNull();
});

it('lets the policy of a model decide in the guarded panel', function (): void {
    Gate::policy(Order::class, OrderGatePolicy::class);
    $member = User::query()->findOrFail(1);
    GateWorld::serve($member);

    expect(FilamentGate::before($member, 'viewAny', [Order::class]))->toBeNull()
        ->and(Gate::forUser($member)->check('viewAny', Order::class))->toBeTrue();
});

it('refuses at boot a resource, page or widget that AzGuard does not decide while the panel enforces', function (array $fixture, string $class): void {
    foreach ($fixture as $property => $value) {
        FilamentFixture::${$property} = $value;
    }
    $this->bootFilament();

    expect(fn () => Filament::getPanel('admin')->boot())->toThrow(ConfigurationException::class, $class);
})->with([
    'resource without the trait' => [['resources' => [OrderResource::class, PlainOrderResource::class]], PlainOrderResource::class],
    'resource that replaces the query' => [['resources' => [QueryOrderResource::class]], 'modifyEloquentQuery'],
    'page without the trait' => [['pages' => [PlainPage::class]], PlainPage::class],
    'widget without the trait' => [['widgets' => [PlainWidget::class]], PlainWidget::class],
]);

it('boots a panel with such classes when it does not enforce or excludes them', function (): void {
    FilamentFixture::$resources = [PlainOrderResource::class];
    FilamentFixture::$exclude = ['resources' => [PlainOrderResource::class]];
    $this->bootFilament();
    Filament::getPanel('admin')->boot();

    FilamentFixture::$exclude = [];
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    Filament::getPanel('admin')->boot();

    expect(Filament::getPanel('admin')->getResources())->toBe([PlainOrderResource::class]);
});

final class OrderGatePolicy
{
    public function viewAny(): bool
    {
        return true;
    }
}
