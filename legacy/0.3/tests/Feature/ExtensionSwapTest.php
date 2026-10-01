<?php

declare(strict_types=1);

use AzGuard\AzGuardManager;
use AzGuard\Configuration\Config;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\SwapTestManager;
use AzGuard\Tests\Stubs\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A resolver an integrator might bind via config('az-guard.resolver'). It records
 * that it was reached and reports every user as a super-admin, so the swap is
 * observable on the real check() path.
 */
class SpyResolver implements PermissionResolverInterface
{
    public static int $forUserCalls = 0;

    public function forUser(Authenticatable $user, string $panelId): PermissionSet
    {
        self::$forUserCalls++;

        return PermissionSet::wildcard();
    }

    public function forgetForUser(Authenticatable $user, string $panelId): void {}

    public function forgetRequestCache(Authenticatable $user, string $panelId): void {}
}

// ─── Config accessors ──────────────────────────────────────────────────────

it('defaults manager/resolver classes and honours config overrides', function () {
    expect(Config::managerClass())->toBe(AzGuardManager::class)
        ->and(Config::resolverClass())->toBe(EffectivePermissionResolver::class);

    config()->set('az-guard.manager', SwapTestManager::class);
    config()->set('az-guard.resolver', SpyResolver::class);

    expect(Config::managerClass())->toBe(SwapTestManager::class)
        ->and(Config::resolverClass())->toBe(SpyResolver::class);
});

// ─── Facade binds to the interface, not the concrete ───────────────────────

it('resolves the facade through the manager interface', function () {
    $accessor = (new ReflectionMethod(AzGuard::class, 'getFacadeAccessor'))->invoke(null);

    expect($accessor)->toBe(AzGuardManagerInterface::class)
        ->and(AzGuard::getFacadeRoot())->toBeInstanceOf(AzGuardManagerInterface::class)
        ->and(AzGuard::getFacadeRoot())->toBe(app(AzGuardManagerInterface::class));
});

// ─── Resolver swap reaches the check() path ────────────────────────────────

it('uses a config-overridden resolver on the check path', function () {
    config()->set('az-guard.resolver', SpyResolver::class);
    app()->forgetScopedInstances();
    SpyResolver::$forUserCalls = 0;

    $user = User::factory()->create();

    expect(AzGuard::isSuperAdmin($user, 'test'))->toBeTrue()
        ->and(SpyResolver::$forUserCalls)->toBeGreaterThan(0)
        ->and(app(PermissionResolverInterface::class))->toBeInstanceOf(SpyResolver::class);
});

// The behavioural manager swap (config set before the provider registers) lives
// in ManagerSwapTest, which boots the app with a swapped manager class.

// ─── Default resolver keeps its scoped (per-request) lifecycle ─────────────

it('preserves the scoped per-request lifecycle for the default resolver', function () {
    $first = app(PermissionResolverInterface::class);
    $second = app(PermissionResolverInterface::class);

    expect($first)->toBeInstanceOf(EffectivePermissionResolver::class)
        ->and($first)->toBe($second);
});
