<?php

declare(strict_types=1);

use AzGuard\Exceptions\PanelNotSetException;
use AzGuard\Facades\AzGuard;
use AzGuard\Support\Panel;
use AzGuard\Tests\Stubs\Project;
use AzGuard\Tests\Stubs\Roles\ScopedFilterRole;
use AzGuard\Tests\Stubs\User;

/**
 * T1 — panel-aware Eloquent global query-scope (bootHasScopedRoles, D5/D9).
 *
 * Exercises the REAL fetch path (assign -> query -> ScopeInterface::apply()),
 * not a pre-seeded cache. Since C-01 removed the runningInConsole() bypass,
 * the scope keys on Auth::check() alone — actingAs() is enough for the global
 * scope closure to execute, the same way it does on a real HTTP request.
 * Without the D9 eager-load fix this recurses infinitely, since Project (the
 * scoped entity) itself uses HasScopedRoles.
 */
describe('T1 — panel-aware query-scope filtering (bootHasScopedRoles)', function (): void {
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

    it('throws PanelNotSetException when NO panel is currently set (C-02, fail-closed default, re-baseline of A1)', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $scoped = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-no-panel-set',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        // No AzGuard::setCurrentPanel() call — matches every Filament request /
        // any route without the azguard.panel middleware (PanelResolver::resolve(null) === null).
        // D27 removed the default-panel fallback, so this is now a REACHABLE
        // branch; the fail-closed default (az-guard.scope.on_missing_panel =
        // 'exception') refuses to guess rather than silently aggregate every
        // scope (the pre-D27/A1 behaviour, now opt-in via 'all' — see below).
        expect(fn () => Project::all())->toThrow(PanelNotSetException::class);
    });

    it('returns no rows when NO panel is set and on_missing_panel=empty (C-02)', function (): void {
        config(['az-guard.scope.on_missing_panel' => 'empty']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $scoped = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-empty-branch',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        expect(Project::all())->toBeEmpty();
    });

    it('still applies an explicit-panel scope when NO panel is set and on_missing_panel=all (C-02, D5 opt-in)', function (): void {
        config(['az-guard.scope.on_missing_panel' => 'all']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $scoped = Project::factory()->create();
        $other = Project::factory()->create();

        $role = createRoleWithClass(['name' => 'scoped-filter-all-branch',
            'level' => 1,
        ], ScopedFilterRole::class);

        $user->assignScopedRole($role, $scoped, panelId: 'panel-a');

        $visibleIds = Project::all()->pluck('id')->all();

        expect($visibleIds)->toBe([$scoped->id])
            ->and($visibleIds)->not->toContain($other->id);
    });
});
