<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConfigurationException;
use AzGuard\Filament\FilamentTenantResolver;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/*
 * The tenant Filament chose is the tenant of the guard panel; the resolver never invents one.
 */

function resolvedTenant(): ?TenantRef
{
    return app(FilamentTenantResolver::class)->resolve(Request::create('/'));
}

it('gives the Filament tenant as the tenant of the guard panel, typed by its tenant model', function (): void {
    Filament::setCurrentPanel('tenanted');
    Filament::setTenant(Team::query()->findOrFail(7), isQuiet: true);

    expect(resolvedTenant())->toEqual(TenantRef::of('team', 7));
});

it('gives null without a Filament tenant', function (): void {
    Filament::setCurrentPanel('tenanted');

    expect(resolvedTenant())->toBeNull();
});

it('gives null for a tenant of another model than the tenant model of the guard panel', function (): void {
    Filament::setCurrentPanel('tenanted');
    Filament::setTenant(User::query()->findOrFail(1), isQuiet: true);

    expect(resolvedTenant())->toBeNull();
});

it('gives null when the guard panel has no tenants', function (): void {
    Filament::setCurrentPanel('admin');
    Filament::setTenant(Team::query()->findOrFail(7), isQuiet: true);

    expect(resolvedTenant())->toBeNull();
});

it('gives null without a current Filament panel', function (): void {
    Filament::setCurrentPanel(null);

    expect(resolvedTenant())->toBeNull();
});

it('enters the guard panel after the tenant is chosen on a panel with tenants', function (): void {
    $tenanted = Filament::getPanel('tenanted');
    $admin = Filament::getPanel('admin');

    expect($tenanted->getTenantMiddleware())->toContain('azguard.panel:teams')
        ->and($tenanted->getAuthMiddleware())->not->toContain('azguard.panel:teams')
        ->and($admin->getAuthMiddleware())->toContain('azguard.panel:admin')
        ->and($admin->getTenantMiddleware())->not->toContain('azguard.panel:admin');
});

it('refuses a plugin registered before tenant(), which would enter the guard panel without the tenant', function (): void {
    FilamentFixture::$tenantAfterPlugin = true;
    $this->bootFilament();

    expect(fn () => Filament::getPanel('tenanted')->boot())
        ->toThrow(ConfigurationException::class, 'call ->tenant(...) before ->plugin(AzGuardPlugin::make())');
});
