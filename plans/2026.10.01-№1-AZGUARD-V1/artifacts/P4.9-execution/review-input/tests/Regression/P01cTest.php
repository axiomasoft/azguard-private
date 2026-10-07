<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;

it('resolves a key of the admin panel to admin and never to the default panel', function (string $key): void {
    [$resolver, $current] = PanelWorld::compile([
        TestPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default(),
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class),
    ]);
    $user = new User;

    expect($resolver->resolve($user)['panel']->id())->toBe('test')
        ->and($resolver->resolve($user, $key)['panel']->id())->toBe('admin')
        ->and($resolver->resolve($user, $key)['key']?->full())->toBe('admin:users.delete')
        ->and($resolver->owner($key, $user)?->id())->toBe('admin');

    $current->set($resolver->resolve(panel: 'test')['panel']);

    expect($resolver->resolve($user, $key)['panel']->id())->toBe('admin')
        ->and($resolver->resolve($user, 'users.delete')['panel']->id())->toBe('test');
})->with(['admin:users.delete', 'admin.users.delete']);
