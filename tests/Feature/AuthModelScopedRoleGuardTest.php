<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ScopedAuthUserSubclass extends User
{
    protected $table = 'users';
}

it('loads the auth-provider model without recursive authentication queries', function (): void {
    $user = User::factory()->create();
    $guard = Auth::guard();

    session([$guard->getName() => $user->getAuthIdentifier()]);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;

        if ($queryCount > 25) {
            throw new RuntimeException('unbounded queries while loading auth-provider model');
        }
    });

    $loaded = $guard->user();

    expect($loaded)->not->toBeNull()
        ->and($loaded->is($user))->toBeTrue()
        ->and($queryCount)->toBeLessThan(10);

    $listed = User::query()->get();

    expect($listed)->toHaveCount(1)
        ->and($listed->first()?->is($user))->toBeTrue();
});

it('still applies HasScopedRoles isolation to non-auth scoped entities', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $scoped = Project::factory()->create();
    $other = Project::factory()->create();

    $role = createRoleWithClass([
        'name' => 'auth-guard-scoped-filter',
        'level' => 1,
    ], ScopedFilterRole::class);

    $user->assignScopedRole($role, $scoped);

    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'panel-b')->label(label: 'B'));

    expect(Project::query()->pluck('id')->all())->toBe([$scoped->id])
        ->and(Project::query()->pluck('id')->all())->not->toContain($other->id);
});

it('applies scoped isolation to a subclass of the configured auth model', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $scoped = ScopedAuthUserSubclass::create([
        'name' => 'Scoped',
        'email' => 'scoped-subclass@example.com',
        'password' => 'password',
    ]);
    $other = ScopedAuthUserSubclass::create([
        'name' => 'Other',
        'email' => 'other-subclass@example.com',
        'password' => 'password',
    ]);
    $role = createRoleWithClass([
        'name' => 'auth-subclass-scoped-filter',
        'level' => 1,
    ], ScopedFilterRole::class);

    $user->assignScopedRole($role, $scoped);
    AzGuard::setCurrentPanel(panel: Panel::make()->id('panel-b'));

    expect(ScopedAuthUserSubclass::query()->pluck('id')->all())->toBe([$scoped->id])
        ->and(ScopedAuthUserSubclass::query()->pluck('id')->all())->not->toContain($other->id);
});
