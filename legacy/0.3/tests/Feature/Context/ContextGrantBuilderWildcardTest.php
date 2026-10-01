<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\ContextGrantBuilder;
use AzGuard\Context\ContextPermissionLayer;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * C-13 — a context grant is scoped by design (one contextType+contextId pair)
 * and must never carry the wildcard/superadmin key: rejecting it at write time
 * closes the escalation vector where a scoped grant would transcend its context.
 */
it('rejects granting the wildcard key in a context grant', function (): void {
    $user = User::factory()->create();

    expect(fn () => (new ContextGrantBuilder($user))
        ->on('app')
        ->inContext('workspace', 42)
        ->grant('*'))
        ->toThrow(InvalidArgumentException::class);

    expect(ContextRole::query()->where('model_id', $user->getAuthIdentifier())->exists())->toBeFalse();
});

it('rejects a permission key containing a wildcard metacharacter', function (): void {
    $user = User::factory()->create();

    expect(fn () => (new ContextGrantBuilder($user))
        ->on('app')
        ->inContext('workspace', 42)
        ->grant('app.documents.*'))
        ->toThrow(InvalidArgumentException::class);
});

it('still grants a valid, non-wildcard permission key', function (): void {
    $user = User::factory()->create();

    (new ContextGrantBuilder($user))
        ->on('app')
        ->inContext('workspace', 42)
        ->grant('app.documents.export');

    expect(ContextRole::query()->where([
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'permission_key' => 'app.documents.export',
    ])->exists())->toBeTrue();
});

/**
 * C-10 — a context grant written via ContextGrantBuilder (which persists the
 * morph ALIAS when Relation::enforceMorphMap() is active) must still be found
 * by ContextPermissionLayer's raw query — both sides must use getMorphClass(),
 * not the raw ::class, or an aliased morph map silently orphans every grant.
 */
it('finds a context grant written under an enforced morph map alias', function (): void {
    Relation::enforceMorphMap(['test-user' => User::class]);

    try {
        $user = User::factory()->create();

        (new ContextGrantBuilder($user))
            ->on('app')
            ->inContext('workspace', 42)
            ->grant('app.documents.export');

        expect(ContextRole::query()->where('model_id', $user->getAuthIdentifier())->value('model_type'))
            ->toBe('test-user');

        $manager = app(AuthorizationContextManager::class);
        $manager->set(new AuthorizationContext('app', 'workspace', 42));

        $layer = new ContextPermissionLayer($manager, new GlobalPlusContextStrategy);
        $result = $layer->apply(PermissionSet::empty(), $user, 'app');

        expect($result->grants('app.documents.export'))->toBeTrue();
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});
