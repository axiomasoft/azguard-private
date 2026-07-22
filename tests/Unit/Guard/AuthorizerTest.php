<?php

declare(strict_types=1);

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Guard\Authorizer;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Roles\ManagerRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $panel = Panel::make()->id('test');
    app(AzGuardManagerInterface::class)->registerPanel($panel);
    app(AzGuardManagerInterface::class)->setCurrentPanel($panel);
});

describe('Authorizer', function () {
    it('rejects a user without Authenticatable at the type level', function () {
        // P2.2: the runtime pass-through moved to the Gate::before closure;
        // check() now demands Authorizable&Authenticatable in its signature.
        $user = new class implements Authorizable
        {
            public function can($abilities, $arguments = []) {}

            public function cant($abilities, $arguments = []) {}

            public function cannot($abilities, $arguments = []) {}
        };

        $authorizer = app(Authorizer::class);

        expect(fn () => $authorizer->check($user, 'some.ability'))->toThrow(TypeError::class);
    });

    it('returns null when panel not set', function () {
        app(AzGuardManagerInterface::class)->setCurrentPanel(null);

        $user = User::factory()->create();
        $authorizer = app(Authorizer::class);

        expect($authorizer->check($user, 'test.posts.view'))->toBeNull();
    });

    it('returns null when user has no roles', function () {
        $user = User::factory()->create();
        $authorizer = app(Authorizer::class);

        expect($authorizer->check($user, 'test.posts.view'))->toBeNull();
    });

    it('returns true when user has permission via class role', function () {
        $user = User::factory()->create();

        $role = createRoleWithClass(['name' => 'manager',
            'level' => 0,
        ], ManagerRole::class);

        $user->assignRole('manager');

        $authorizer = app(Authorizer::class);

        // ManagerRole grants 'test.post.view' based on panel
        expect($authorizer->check($user, 'test.post.view'))->toBeTrue();
    });

    it('returns null when user does not have ability', function () {
        $user = User::factory()->create();

        $role = createRoleWithClass(['name' => 'manager',
            'level' => 0,
        ], ManagerRole::class);

        $user->assignRole('manager');

        $authorizer = app(Authorizer::class);

        expect($authorizer->check($user, 'test.admin.delete'))->toBeNull();
    });
});
