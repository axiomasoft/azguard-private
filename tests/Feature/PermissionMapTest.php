<?php

declare(strict_types=1);

use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Panels\TestAdminPanelProvider;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

test('panel provider регистрирует abilities из Permission::map', function () {
    (new TestAdminPanelProvider(app()))->boot();

    $panel = (new TestAdminPanelProvider(app()))->panel(Panel::make());
    $expectedAbility = $panel->id(id: 'admin')->resolvePermission(permission: 'post.view');

    expect(Gate::has($expectedAbility))->toBeTrue();
});

test('Gate ability из map проверяет hasPermission', function () {
    (new TestAdminPanelProvider(app()))->boot();

    $user = User::create([
        'name' => 'Test',
        'email' => 'map@example.com',
        'password' => 'password',
    ]);

    $user->setRelation('roles', collect());

    $this->actingAs($user);

    expect(Gate::allows('admin.post.view'))->toBeFalse();
});
