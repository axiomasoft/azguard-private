<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;

it('picks the panel of a full name without a current panel and throws for a local name', function (): void {
    [$resolver, $current] = PanelWorld::adminAndCabinet();
    $user = new User;

    expect($current->get())->toBeNull()
        ->and($resolver->resolve($user, 'admin:users.delete')['panel']->id())->toBe('admin')
        ->and($resolver->owner('admin:users.delete', $user)?->id())->toBe('admin')
        ->and($resolver->owner('admin.users.delete', $user)?->id())->toBe('admin')
        ->and(fn () => $resolver->resolve($user, 'users.delete'))->toThrow(PanelNotResolvedException::class);
});
