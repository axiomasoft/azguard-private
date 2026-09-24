<?php

declare(strict_types=1);

use AzGuard\Context\ContextGrantBuilder;
use AzGuard\Context\ContextNotSetException;
use AzGuard\Tests\Stubs\User;

it('explains that inContext() must be called first', function (): void {
    $user = User::factory()->create();

    expect(fn () => (new ContextGrantBuilder($user))->on('test')->grant('test.post.view'))
        ->toThrow(ContextNotSetException::class, 'Call ->inContext(');
});
