<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\Permissions\TestPermission;
use AzGuard\Tests\Stubs\User;

/** A typed panel identifier — used instead of the magic string 'test'. */
enum TestPanelId: string
{
    case Test = 'test';
}

it('resolves a panel and permission from a backed enum panel id', function () {
    expect(AzGuard::panel(TestPanelId::Test))->not->toBeNull();

    expect(AzGuard::permission(TestPanelId::Test, TestPermission::PostView))
        ->toBe('test.post.view');

    expect(AzGuard::tryPermission(TestPanelId::Test, TestPermission::PostView))
        ->toBe('test.post.view');
});

it('grants and lists direct grants with a backed enum panel id', function () {
    $user = User::create([
        'name' => 'Panel Enum User',
        'email' => 'panelenum@example.com',
        'password' => 'password',
    ]);

    AzGuard::forUser($user)->on(TestPanelId::Test)->grant(TestPermission::PostView);

    $grants = AzGuard::forUser($user)->on(TestPanelId::Test)->grants();

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->panel_id)->toBe('test')
        ->and($grants->first()->permission_key)->toBe('test.post.view');
});

it('grants and revokes via the manager shorthand with an enum panel id', function () {
    $user = User::create([
        'name' => 'Panel Enum User 2',
        'email' => 'panelenum2@example.com',
        'password' => 'password',
    ]);

    AzGuard::grant($user, TestPermission::PostView, TestPanelId::Test);
    expect(AzGuard::grants($user, TestPanelId::Test))->toHaveCount(1);

    AzGuard::revoke($user, TestPermission::PostView, TestPanelId::Test);
    expect(AzGuard::grants($user, TestPanelId::Test))->toHaveCount(0);
});
