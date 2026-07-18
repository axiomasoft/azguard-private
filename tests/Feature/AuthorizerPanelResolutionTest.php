<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Support\Panel;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

/**
 * B2: with no active panel, the Authorizer must not silently evaluate an
 * ability against an arbitrarily-picked panel. It uses the sole panel when
 * unambiguous, an explicit default_panel when configured, and otherwise denies.
 */
beforeEach(function () {
    $this->manager = app(AzGuardManagerInterface::class);

    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'panel-res@example.com',
        'password' => 'password',
    ]);

    $role = createRoleWithClass(['name' => 'manager', 'level' => 0], ManagerRole::class);
    $this->user->roles()->attach($role);
    $this->user->load('roles');

    $this->actingAs($this->user);
});

it('denies when no panel is active and several panels are registered', function () {
    // Register a second panel so resolution is ambiguous.
    $this->manager->registerPanel(Panel::make()->id('second'));
    $this->manager->setCurrentPanel(null);

    expect($this->manager->getPanels())->toHaveCount(2)
        ->and(Gate::allows('test.post.view'))->toBeFalse();
});

it('uses the sole registered panel when only one exists', function () {
    $this->manager->setCurrentPanel(null);

    expect($this->manager->getPanels())->toHaveCount(1)
        ->and(Gate::allows('test.post.view'))->toBeTrue();
});

it('honours az-guard.default_panel when configured and registered', function () {
    config()->set('az-guard.default_panel', 'test');

    $this->manager->registerPanel(Panel::make()->id('second'));
    $this->manager->setCurrentPanel(null);

    expect(Gate::allows('test.post.view'))->toBeTrue();
});
