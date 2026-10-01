<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\SwapTestManager;

it('boots with a config-overridden manager and routes core checks through it', function () {
    expect(app(AzGuardManagerInterface::class))->toBeInstanceOf(SwapTestManager::class)
        ->and(AzGuard::getFacadeRoot())->toBeInstanceOf(SwapTestManager::class)
        ->and(app(AzGuardManagerInterface::class)->getPanels())->toHaveKey('test');

    // A real check must still resolve through the swapped manager (its panels
    // drive catalog/panel resolution), proving the swap reaches core paths.
    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});
