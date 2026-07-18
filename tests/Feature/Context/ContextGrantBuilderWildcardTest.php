<?php

declare(strict_types=1);

use AzGuard\Context\ContextGrantBuilder;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Tests\Stubs\User;

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
