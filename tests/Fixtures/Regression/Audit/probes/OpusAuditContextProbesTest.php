<?php

declare(strict_types=1);

/**
 * Opus audit probes for the context package (2026-09-29). A passing probe
 * demonstrates the CURRENT (defective) behaviour described in
 * audits/2026-09-29-audit/opus/01-review.md. Not part of the product suite.
 */

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Context\Strategies\DenyWithoutContextStrategy;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Builders\EnumPermissionCatalogBuilder;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Tests\ContextTestCase;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(ContextTestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/packages/context/database/migrations');
});

enum OpusProbeCtxAdminPermission: string
{
    case UsersDelete = 'users.delete';
}

function opusCtxRegisterAdminPanel(): void
{
    AzGuard::registerPanel(Panel::make()->id('admin')->permissionEnums([OpusProbeCtxAdminPermission::class]));
    $abstract = 'azguard.catalog_builder.admin.enum';
    app()->instance($abstract, new EnumPermissionCatalogBuilder(panelId: 'admin', enumClasses: [OpusProbeCtxAdminPermission::class]));
    app()->tag([$abstract], 'azguard.catalog_builders');
    app()->forgetInstance(PermissionCatalog::class);
    app()->forgetScopedInstances();
}

// ─── P05 — the Vaulter bridge call shape (N05) ──────────────────────────────

it('P05: hasPermissionIn() with an unqualified key and panelId null denies although the grant exists', function (): void {
    config()->set('az-guard.default_panel', 'test');
    $user = UserWithDirectGrants::factory()->create();
    AzGuard::forUser($user)->on('test')->inContext('project', 7)->grant('test.post.view');
    app()->forgetScopedInstances();

    // What vaulter-azgard sends: permission map value 'post.view'-style key, panel null.
    expect($user->hasPermissionIn('project', 7, 'post.view'))->toBeFalse()
        ->and($user->hasPermissionIn('project', 7, 'test.post.view'))->toBeTrue();
});

// ─── P06 — one global merge strategy for every panel (N07) ──────────────────

it('P06: DenyWithoutContext configured for the tenant panel also zeroes the admin panel', function (): void {
    opusCtxRegisterAdminPanel();
    app()->bind(MergeStrategy::class, DenyWithoutContextStrategy::class);
    app()->forgetScopedInstances();

    $admin = UserWithDirectGrants::factory()->create();
    $admin->grant('admin.users.delete', 'admin');
    app()->forgetScopedInstances();

    expect($admin->hasPermission('admin.users.delete', 'admin'))->toBeFalse();
});

// ─── P07 — C01 end-to-end: two distinct contexts share one durable cache entry ─

it('P07: a grant in context (workspace, "a:7") is served for context ("workspace:a", 7) from the durable cache', function (): void {
    config()->set('cache.stores.opus_probe', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'opus_probe');
    config()->set('az-guard.default_panel', 'test');

    $user = UserWithDirectGrants::factory()->create();
    AzGuard::forUser($user)->on('test')->inContext('workspace', 'a:7')->grant('test.post.view');

    // Request 1: the member's own workspace.
    app()->forgetScopedInstances();
    app(AuthorizationContextManager::class)->set(new AuthorizationContext('test', 'workspace', 'a:7'));
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // Request 2: a different context whose discriminator string is identical.
    app()->forgetScopedInstances();
    app(AuthorizationContextManager::class)->set(new AuthorizationContext('test', 'workspace:a', 7));
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    // Ground truth without the cache: no grant exists in ("workspace:a", 7).
    config()->set('az-guard.cache.store', 'array');
    app()->forgetScopedInstances();
    app(AuthorizationContextManager::class)->set(new AuthorizationContext('test', 'workspace:a', 7));
    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse();
});
