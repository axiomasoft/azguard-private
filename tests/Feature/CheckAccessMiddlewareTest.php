<?php

declare(strict_types=1);

use AzGuard\Attributes\CheckPermission;
use AzGuard\Attributes\SkipGuardCheck;
use AzGuard\Facades\AzGuard;
use AzGuard\Http\Middleware\CheckAccess;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

it('пропускает invokable-контроллер с CheckPermission', function (): void {
    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'test'));

    Gate::define('test.post.view', fn (User $user): bool => true);

    Route::middleware(['web', CheckAccess::class])
        ->get('/azguard-invoke-test', InvokablePostController::class);

    $user = new User;
    $user->id = 1;

    $this->actingAs(user: $user)
        ->get(uri: '/azguard-invoke-test')
        ->assertSuccessful();
});

it('пропускает метод с SkipGuardCheck без Gate', function (): void {
    Route::middleware(['web', CheckAccess::class])
        ->get('/azguard-skip-test', [SkipGuardController::class, 'show']);

    $this->get(uri: '/azguard-skip-test')
        ->assertSuccessful();
});

it('returns 403 when the gate denies the authenticated user', function (): void {
    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'test'));

    Gate::define('test.post.view', fn (User $user): bool => false);

    Route::middleware(['web', CheckAccess::class])
        ->get('/azguard-deny-test', InvokablePostController::class);

    $user = new User;
    $user->id = 1;

    $this->actingAs(user: $user)
        ->get(uri: '/azguard-deny-test')
        ->assertForbidden();
});

it('denies a guest when the ability requires a user', function (): void {
    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'test'));

    Gate::define('test.post.view', fn (User $user): bool => true);

    Route::middleware(['web', CheckAccess::class])
        ->get('/azguard-guest-test', InvokablePostController::class);

    $this->get(uri: '/azguard-guest-test')
        ->assertForbidden();
});

it('propagates the configured status and message on denial', function (): void {
    AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'test'));

    Gate::define('test.post.view', fn (User $user): bool => false);

    Route::middleware(['web', CheckAccess::class])
        ->get('/azguard-custom-status', CustomStatusController::class);

    $user = new User;
    $user->id = 1;

    $this->actingAs(user: $user)
        ->get(uri: '/azguard-custom-status')
        ->assertStatus(419);
});

final class InvokablePostController
{
    #[CheckPermission(permission: PostPermission::View)]
    public function __invoke(): string
    {
        return 'ok';
    }
}

final class SkipGuardController
{
    #[SkipGuardCheck]
    public function show(): string
    {
        return 'ok';
    }
}

final class CustomStatusController
{
    #[CheckPermission(permission: PostPermission::View, status: 419, message: 'nope')]
    public function __invoke(): string
    {
        return 'ok';
    }
}
