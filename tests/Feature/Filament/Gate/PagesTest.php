<?php

declare(strict_types=1);

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Models\Visitor;
use AzGuard\Tests\Fixtures\Filament\Pages\ReportsPage;
use AzGuard\Tests\Fixtures\Filament\Widgets\OrderCountWidget;
use Filament\Facades\Filament;

/*
 * V25: a page with AuthorizesPage in a Filament panel that enforces opens with its permission only; a user without the
 * permission and a user model that holds no roles get 403. A widget is shown by its permission in the same way.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    FilamentFixture::$memberPermissions = [];
    $this->bootFilament();
    GateWorld::seed();
});

it('V25 opens a page with its permission and refuses a member without it', function (): void {
    $this->actingAs(User::query()->findOrFail(1));
    $this->get('/admin/reports')->assertForbidden();

    GateWorld::grant(['pages.reports']);
    $this->get('/admin/reports')->assertOk();
});

it('V25 refuses a user model that is no AzGuardSubject, also when the guard panel admits its row', function (): void {
    GateWorld::grant(['pages.reports']);
    $this->actingAs(Visitor::query()->findOrFail(1));

    $this->get('/admin/reports')->assertForbidden();
    expect(FilamentGate::page(ReportsPage::class)->code())->toBe('azguard.filament.subject');
});

it('refuses a page whose permission has no definition while the panel enforces', function (): void {
    FilamentFixture::$memberPermissions = ['pages.*'];
    FilamentFixture::$pages = [ReportsPage::class];
    FilamentFixture::$guardPermissions = [];
    FilamentFixture::$guardPolicies = [];
    $this->bootFilament();
    GateWorld::seed();
    $this->actingAs(User::query()->findOrFail(1));

    $this->get('/admin/reports')->assertForbidden();
    expect(FilamentGate::page(ReportsPage::class)->code())->toBe('azguard.filament.definition');
});

it('leaves a page without a definition to Filament when the panel does not enforce', function (): void {
    FilamentFixture::$enforce = false;
    FilamentFixture::$guardPermissions = [];
    FilamentFixture::$guardPolicies = [];
    $this->bootFilament();
    GateWorld::seed();
    $this->actingAs(User::query()->findOrFail(1));

    $this->get('/admin/reports')->assertOk();
});

it('decides a known page permission also when the panel does not enforce', function (): void {
    FilamentFixture::$enforce = false;
    $this->bootFilament();
    GateWorld::seed();
    $this->actingAs(User::query()->findOrFail(1));

    $this->get('/admin/reports')->assertForbidden();
});

it('shows a widget with its permission only', function (): void {
    $member = User::query()->findOrFail(1);
    GateWorld::serve($member);

    expect(OrderCountWidget::canView())->toBeFalse();

    GateWorld::grant(['widgets.order-count']);
    expect(OrderCountWidget::canView())->toBeTrue();
});

it('leaves pages and widgets of a Filament panel without the plugin to Filament', function (): void {
    GateWorld::serve(User::query()->findOrFail(2), 'plain');

    expect(Filament::getCurrentPanel()?->getId())->toBe('plain')
        ->and(ReportsPage::canAccess())->toBeTrue()
        ->and(OrderCountWidget::canView())->toBeTrue();
});
