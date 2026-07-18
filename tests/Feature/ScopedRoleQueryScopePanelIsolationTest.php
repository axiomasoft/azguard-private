<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Support\Panel;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\User;

/**
 * T1 — panel-aware Eloquent global query-scope (bootHasScopedRoles, D5/D9).
 *
 * Exercises the REAL fetch path (assign -> query -> ScopeInterface::apply()),
 * not a pre-seeded cache — the console-guard is forced off (bootHasScopedRoles
 * no-ops under app()->runningInConsole(), true in every Pest run) so the
 * global scope closure actually executes, the same way it does on a real
 * HTTP request. Without the D9 eager-load fix this recurses infinitely,
 * since Project (the scoped entity) itself uses HasScopedRoles.
 */
function bypassScopedRolesConsoleGuard(): void
{
    (function (): void {
        $this->isRunningInConsole = false;
    })->call(app());
}

describe('T1 — panel-aware query-scope filtering (bootHasScopedRoles)', function (): void {
    beforeEach(function (): void {
        bypassScopedRolesConsoleGuard();
    });

    afterEach(function (): void {
        AzGuard::setCurrentPanel(panel: null);
    });

    it('does not let a scope assigned under panel A narrow a query made under panel B', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $scopedUnderA = Project::factory()->create();
        $independent = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-panel-a',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scopedUnderA, panelId: 'panel-a');

        AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'panel-b')->label(label: 'B'));

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toContain($independent->id, $scopedUnderA->id);
    });

    it('honours a null-panel scope under ANY active panel (back-compat)', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-null-panel',
            'level' => 1,
        ], ScopedFilterRole::class);

        // No panelId => persisted panel_id is null => "any panel" back-compat.
        $user->assignScopedRole($role, $scoped);

        AzGuard::setCurrentPanel(panel: Panel::make()->id(id: 'panel-b')->label(label: 'B'));

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toBe([$scoped->id])
            ->and($visibleIds)->not->toContain($other->id);
    });

    it('still applies an explicit-panel scope when NO panel is currently set (D5, anti-regression A1)', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-no-panel-set',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        // No AzGuard::setCurrentPanel() call — matches every Filament request /
        // any route without the azguard.panel middleware (PanelResolver::resolve(null) === null).
        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toBe([$scoped->id])
            ->and($visibleIds)->not->toContain($other->id);
    });
});
