<?php

declare(strict_types=1);

use AzGuard\Http\Middleware\LoadAzGuardRoles;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('eager loads roles for authenticated user', function (): void {
    $user = User::factory()->create();

    Route::middleware(['web', LoadAzGuardRoles::class])
        ->get('/load-roles-test', fn (): string => (string) $user->roles->count());

    DB::enableQueryLog();

    $this->actingAs(user: $user)
        ->get('/load-roles-test')
        ->assertOk();

    $queries = DB::getQueryLog();

    $hasRolesQuery = collect($queries)
        ->contains(fn (array $query): bool => str_contains($query['query'], 'model_has_roles'));

    expect($hasRolesQuery)->toBeTrue();
});

it('does nothing for guest user', function (): void {
    Route::middleware(['web', LoadAzGuardRoles::class])
        ->get('/load-roles-guest', fn (): string => 'ok');

    DB::enableQueryLog();

    $this->get('/load-roles-guest')
        ->assertOk();

    $queries = DB::getQueryLog();

    $hasRolesQuery = collect($queries)
        ->contains(fn (array $query): bool => str_contains($query['query'], 'model_has_roles'));

    expect($hasRolesQuery)->toBeFalse();
});
