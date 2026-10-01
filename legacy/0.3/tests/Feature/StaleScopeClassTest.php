<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * C-03 — a stale scope_class (the RoleInterface class was renamed/removed
 * after model_has_scopes rows were persisted) used to silently no-op inside
 * bootHasScopedRoles(). It must now warn loudly (once per request) instead.
 */
it('logs a warning once per request when scope_class does not exist', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    DB::table('model_has_scopes')->insert([
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'scope_entity_type' => $project->getMorphClass(),
        'scope_entity_id' => $project->getKey(),
        'scope_class' => 'AzGuard\\Tests\\Stubs\\Roles\\ThisClassWasDeleted',
        'panel_id' => null,
    ]);

    Auth::login($user);
    config(['az-guard.scope.on_missing_panel' => 'all']);

    Log::spy();

    // Two separate queries in the same request — the warning must fire only once.
    Project::query()->get();
    Project::query()->get();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'stale scope_class'))
        ->once();
});
