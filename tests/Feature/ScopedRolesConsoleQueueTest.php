<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Facades\AzGuard;
use AzGuard\Panels\Panel;
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
    it('fails closed for a queue-context actor with no panel set (C-02 re-baseline, not a silent bypass)', function (): void {
        $user = User::factory()->create();

        $scoped = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-queue',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        // Simulates a queue job resolving the authenticated actor without
        // an HTTP request: Auth::login() (not actingAs()/TestCase HTTP
        // helpers), no AzGuard::setCurrentPanel() call. Proof the scope is
        // genuinely ACTIVE (not bypassed, C-01): a bypassed/unscoped query
        // would just return every row, not throw. With no panel active, the
        // fail-closed default (C-02, az-guard.scope.on_missing_panel =
        // 'exception') refuses to guess rather than silently under/over-scope.
        Auth::login($user);

        expect(fn () => Project::all())->toThrow(PanelNotSetException::class);
    });

    it('filters a scoped query for a queue-context actor once the job sets its panel', function (): void {
        $user = User::factory()->create();

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-queue-panel',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        Auth::login($user);
        // A well-behaved queue job sets its panel explicitly before touching
        // scoped models — the productive counterpart to the fail-closed test
        // above.
        AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'panel-a')->label(label: 'A'));

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toBe([$scoped->id])
            ->and($visibleIds)->not->toContain($other->id);

        AzGuard::setCurrentPanel(panel: null);
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
