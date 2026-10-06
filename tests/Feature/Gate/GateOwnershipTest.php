<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use AzGuard\Tests\Fixtures\Gate\NativeFallbackPolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\TenantDynamicSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('makes owned NotGranted and Policy denies final despite a matching permissive native definition', function (string $ability): void {
    GateWorld::compile(new GeneratedSource);
    RuntimePolicy::$result = false;
    Gate::define($ability, fn () => true);
    expect(Gate::forUser(User::findOrFail(1))->allows($ability))->toBeFalse();
})->with(['admin:orders.view', 'admin:orders.policy', 'orders.view']);

it('denies unknown qualified and registered prefix names and leaves foreign names untouched without queries', function (): void {
    $source = new GeneratedSource;
    GateWorld::compile($source);
    $user = User::findOrFail(1);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    foreach (['foreign.word', 'foreign:orders.view', 'update'] as $ability) {
        expect(app(GateBridge::class)($user, $ability))->toBeNull();
        Gate::define($ability, fn () => true);
        expect(Gate::forUser($user)->allows($ability))->toBeTrue();
    }
    expect($queries)->toBe([])->and($source->grantReads)->toBe(0);
    foreach (['admin:orders.unknown', 'admin.orders.unknown', 'admin:invalid..name'] as $ability) {
        Gate::define($ability, fn () => true);
        expect(Gate::forUser($user)->allows($ability))->toBeFalse();
    }
});

it('uses the model ability index including class arguments and denies ambiguous pairs', function (): void {
    $grant = Grant::of(PermissionPattern::of('admin', 'inventory.view_any'), 'generated', AccessScope::in(TenantRef::global()));
    GateWorld::compile(new GeneratedSource(direct: [$grant]));
    $user = User::findOrFail(1);
    expect(Gate::forUser($user)->allows('viewAny', GateRecord::class))->toBeTrue();
    Gate::define('read', fn () => true);
    expect(Gate::forUser($user)->allows('read', new GateRecord))->toBeFalse();
    Gate::define('update', fn () => true);
    expect(Gate::forUser($user)->allows('update', new GateRecord))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', new User))->toBeTrue();
});

it('denies explicit owned names for guests and unacceptable subject types while foreign abilities abstain', function (): void {
    GateWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    expect(app(GateBridge::class)(null, 'admin:orders.view'))->not->toBeNull()
        ->and(app(GateBridge::class)(null, 'native.word'))->toBeNull();
    $outsider = new GateRecord;
    $outsider->setAttribute('id', 1);
    expect(app(GateBridge::class)($outsider, 'admin:orders.view')->denied())->toBeTrue()
        ->and(app(GateBridge::class)($outsider, 'orders.view'))->toBeNull();
});

it('resolves local dynamic ownership once and abstains for a missing dynamic name', function (): void {
    $scope = AccessScope::in(TenantRef::of('org', 'A'));
    $source = new TenantDynamicSource(name: 'dynamic', direct: [Grant::of(PermissionPattern::of('admin', 'orders.dynamic'), 'generated', $scope)]);
    GateWorld::compile(new GeneratedSource, fn ($p) => $p->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership(['1']))), extra: [$source]);
    $user = User::findOrFail(1);
    expect(Gate::forUser($user)->allows('orders.dynamic', [null, $scope]))->toBeTrue();
    Gate::define('foreign.dynamic', fn () => true);
    $reads = $source->grantReads;
    expect(Gate::forUser($user)->allows('foreign.dynamic', [null, $scope]))->toBeTrue()
        ->and($source->grantReads)->toBe($reads);
});

it('leaves an unbound model ability to the native Laravel policy', function (): void {
    GateWorld::compile(new GeneratedSource);
    Gate::policy(User::class, NativeFallbackPolicy::class);
    $user = User::findOrFail(1);
    expect(app(GateBridge::class)($user, 'update', [$user]))->toBeNull()
        ->and(Gate::forUser($user)->allows('update', $user))->toBeTrue();
});

it('uses mapped external gate policies without reentering the bridge or generating Grants authority', function (): void {
    Gate::define('host-update', fn () => true);
    $mapping = GateSource::make()->map('inventory.update', 'host-update');
    $source = new GeneratedSource;
    GateWorld::compile($source, extra: [$mapping]);
    $user = User::findOrFail(1);
    expect(Gate::forUser($user)->allows('admin:inventory.update'))->toBeFalse();
    $source->direct = [Grant::of(PermissionPattern::of('admin', 'inventory.update'), 'generated', AccessScope::in(TenantRef::global()))];
    expect(Gate::forUser($user)->allows('admin:inventory.update'))->toBeTrue();
});

it('rejects an explicit permission supplied with a conflicting resource class', function (): void {
    $grant = Grant::of(PermissionPattern::of('admin', 'inventory.view_any'), 'generated', AccessScope::in(TenantRef::global()));
    GateWorld::compile(new GeneratedSource(direct: [$grant]));
    expect(Gate::forUser(User::findOrFail(1))->allows('admin:inventory.view_any', User::class))->toBeFalse();
});
