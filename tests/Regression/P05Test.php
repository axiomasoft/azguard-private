<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;

it('throws for an unqualified key without a panel and resolves it with an explicit panel', function (): void {
    [$resolver] = PanelWorld::adminAndCabinet();
    $user = new User;

    expect(fn () => $resolver->resolve($user, 'orders.view'))->toThrow(PanelNotResolvedException::class, 'Name the panel')
        ->and($resolver->resolve($user, 'orders.view', 'cabinet')['panel']->id())->toBe('cabinet')
        ->and($resolver->resolve($user, 'orders.view', 'cabinet')['key']?->full())->toBe('cabinet:orders.view');
});
