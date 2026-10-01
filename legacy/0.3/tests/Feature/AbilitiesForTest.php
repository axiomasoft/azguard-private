<?php

declare(strict_types=1);

use AzGuard\Contracts\AbilitiesResolver;
use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Stubs\User;
use Illuminate\Contracts\Auth\Authenticatable;

// A custom resolver an integrator binds via config('az-guard.abilities_resolver').
class StubAbilitiesResolver implements AbilitiesResolver
{
    public function forUser(Authenticatable $user, string $panelId, array $keys): array
    {
        return array_fill_keys($keys, true);
    }
}

// F37: AzGuard::abilitiesFor() projects a CURATED map — only the requested keys.

it('projects only the requested ability keys as a bool map', function () {
    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    $abilities = AzGuard::abilitiesFor($user, 'test', ['post.view', 'post.delete']);

    expect($abilities)->toBe([
        'post.view' => true,
        'post.delete' => false,
    ]);
});

it('never leaks keys outside the requested allowlist', function () {
    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    $abilities = AzGuard::abilitiesFor($user, 'test', ['post.view']);

    expect($abilities)->toHaveCount(1)
        ->and($abilities)->toHaveKey('post.view');
});

it('accepts already-resolved full keys', function () {
    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    expect(AzGuard::abilitiesFor($user, 'test', ['test.post.view']))
        ->toBe(['test.post.view' => true]);
});

it('uses a config-overridden abilities resolver', function () {
    config()->set('az-guard.abilities_resolver', StubAbilitiesResolver::class);
    app()->forgetInstance(AbilitiesResolver::class);

    $user = User::factory()->create();

    expect(AzGuard::abilitiesFor($user, 'test', ['anything']))
        ->toBe(['anything' => true]);
});
