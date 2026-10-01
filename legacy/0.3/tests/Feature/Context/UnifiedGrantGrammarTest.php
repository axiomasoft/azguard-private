<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\ContextPermissionLayer;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Contracts\ContextGrantBuilder as ContextGrantBuilderContract;
use AzGuard\Contracts\ContextGrantBuilderFactory;
use AzGuard\Exceptions\ContextPackageNotInstalledException;
use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Carbon;

/**
 * P2.3 (D16) — the unified immutable grant grammar: one fluent root
 * AzGuard::forUser() for core AND context, the chain reads as a phrase:
 *
 *   AzGuard::forUser($user)->on('app')->inContext('workspace', 42)
 *       ->until($expiry)->grant('app.documents.export');
 */
it('grants a context permission through the unified fluent root', function (): void {
    $user = User::factory()->create();

    $builder = AzGuard::forUser($user)->inContext('workspace', 42);

    expect($builder)->toBeInstanceOf(ContextGrantBuilderContract::class);

    $builder->on('app')->grant('app.documents.export');

    expect(ContextRole::query()->where([
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'panel_id' => 'app',
        'permission_key' => 'app.documents.export',
    ])->exists())->toBeTrue();
});

it('reads as the canonical phrase: forUser→on→inContext→until→grant', function (): void {
    $user = User::factory()->create();
    $expiry = Carbon::parse('2030-01-01 00:00:00');

    AzGuard::forUser($user)
        ->on('app')
        ->inContext('workspace', 42)
        ->until($expiry)
        ->grant('app.documents.export');

    $row = ContextRole::query()->where('model_id', $user->getAuthIdentifier())->sole();

    expect($row->expires_at->eq($expiry))->toBeTrue();
});

it('carries panel and expiry scope set BEFORE inContext() into the context branch', function (): void {
    $user = User::factory()->create();
    $expiry = Carbon::parse('2030-06-01 00:00:00');

    // Scope accumulated on the core root survives the handover to the branch.
    AzGuard::forUser($user)
        ->on('app')
        ->until($expiry)
        ->inContext('workspace', 7)
        ->grant('app.documents.export');

    $row = ContextRole::query()->where('model_id', $user->getAuthIdentifier())->sole();

    expect($row->panel_id)->toBe('app')
        ->and($row->context_type)->toBe('workspace')
        ->and($row->expires_at->eq($expiry))->toBeTrue();
});

it('supports ttl() in the context branch', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

    $user = User::factory()->create();

    AzGuard::forUser($user)
        ->on('app')
        ->inContext('workspace', 42)
        ->ttl(3600)
        ->grant('app.documents.export');

    $row = ContextRole::query()->where('model_id', $user->getAuthIdentifier())->sole();

    expect($row->expires_at->eq(Carbon::parse('2025-01-01 13:00:00')))->toBeTrue();
});

it('is idempotent: re-granting updates expires_at only (parity with core)', function (): void {
    $user = User::factory()->create();

    $base = AzGuard::forUser($user)->on('app')->inContext('workspace', 42);

    $base->grant('app.documents.export');                                   // permanent
    $base->until(Carbon::parse('2030-01-01'))->grant('app.documents.export'); // re-stamp

    expect(ContextRole::query()->where('model_id', $user->getAuthIdentifier())->count())->toBe(1)
        ->and(ContextRole::query()->where('model_id', $user->getAuthIdentifier())->sole()
            ->expires_at->eq(Carbon::parse('2030-01-01')))->toBeTrue();
});

it('is immutable: branches from one context builder are independent', function (): void {
    $user = User::factory()->create();

    $base = AzGuard::forUser($user)->on('app')->inContext('workspace', 42);
    $expiring = $base->until(Carbon::parse('2030-01-01'));

    expect($expiring)->not->toBe($base);

    // The base branch is untouched by the expiring branch — grants permanent.
    $base->grant('app.documents.export');

    $row = ContextRole::query()->where('model_id', $user->getAuthIdentifier())->sole();

    expect($row->expires_at)->toBeNull();
});

// ─── TTL parity: expired context grants are inert ────────────────────────────

it('excludes expired context grants from grants()', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-06-01 12:00:00'));

    $user = User::factory()->create();
    $base = AzGuard::forUser($user)->on('app')->inContext('workspace', 42);

    $base->grant('app.documents.view');                                  // permanent
    $base->until(Carbon::parse('2025-01-01'))->grant('app.documents.export'); // expired

    $active = $base->grants();

    expect($active)->toHaveCount(1)
        ->and($active->first()->permission_key)->toBe('app.documents.view');
});

it('does not confer permissions from an expired context grant (layer honors expiry)', function (): void {
    Carbon::setTestNow(Carbon::parse('2025-06-01 12:00:00'));

    $user = User::factory()->create();
    $base = AzGuard::forUser($user)->on('app')->inContext('workspace', 42);

    $base->grant('app.documents.view');                                  // permanent
    $base->until(Carbon::parse('2025-01-01'))->grant('app.documents.export'); // expired

    $manager = app(AuthorizationContextManager::class);
    $manager->set(new AuthorizationContext('app', 'workspace', 42));

    $layer = new ContextPermissionLayer($manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $user, 'app');

    expect($result->grants('app.documents.view'))->toBeTrue()
        ->and($result->grants('app.documents.export'))->toBeFalse();
});

// ─── Fail-closed without the context package ─────────────────────────────────

it('throws a loud, actionable error when the context package is not bound', function (): void {
    $user = User::factory()->create();

    unset(app()[ContextGrantBuilderFactory::class]);

    expect(fn () => AzGuard::forUser($user)->on('app')->inContext('workspace', 42))
        ->toThrow(ContextPackageNotInstalledException::class);
});
