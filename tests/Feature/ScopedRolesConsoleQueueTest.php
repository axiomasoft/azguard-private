<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Auth;

/**
 * C-01 — query-scope must apply in console/queue contexts, not just HTTP.
 *
 * Before the fix, bootHasScopedRoles() early-returned whenever
 * app()->runningInConsole() was true — which it is for a `queue:work`
 * worker (and every Pest test process). A job running under
 * Auth::login($user) (no HTTP request, no panel middleware) silently
 * read scoped models WITHOUT the isolation filter. Activation is now
 * keyed on Auth::check() alone (D10 a / D27), never on the SAPI.
 *
 * Deliberately does NOT force isRunningInConsole = false (contrast with
 * ScopedRoleQueryScopePanelIsolationTest's bypassScopedRolesConsoleGuard())
 * — the whole point is that the scope now applies even though the process
 * genuinely is running in console.
 */
describe('C-01 — bootHasScopedRoles applies in console/queue contexts (Auth::check(), not SAPI)', function (): void {
    it('filters a scoped query for a queue-context actor (Auth::login, no panel set)', function (): void {
        $user = User::factory()->create();

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-queue',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        // Simulates a queue job resolving the authenticated actor without
        // an HTTP request: Auth::login() (not actingAs()/TestCase HTTP
        // helpers), no AzGuard::setCurrentPanel() call.
        Auth::login($user);

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toBe([$scoped->id])
            ->and($visibleIds)->not->toContain($other->id);
    });

    it('remains a no-op for a genuine console run without an authenticated actor', function (): void {
        $user = User::factory()->create();

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-console',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        // No Auth::login()/actingAs() — Auth::check() === false, so the
        // early return still fires: an artisan command with no logged-in
        // actor sees everything, unfiltered.
        expect(Auth::check())->toBeFalse();

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toContain($scoped->id, $other->id);
    });
});
