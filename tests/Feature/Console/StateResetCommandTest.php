<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Events\PanelStateTouched;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    app()->instance('env', 'testing');
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Anna (user 1) views client 1 of P1 in a fresh request scope. */
function stateResetDecision(): DecisionReason
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return CrmWorld::decide(app(PanelRegistry::class)->get('crm'))->reason;
}

it('V100 R57 starts a new incarnation and version so a decision cached before a manual change does not survive', function (): void {
    W::panel(configure: static fn (PanelBuilder $panel) => $panel->cache(store: 'array', ttl: 3600)->plugins([AuditPlugin::make()]));
    $before = CrmWorld::storage()->state('crm');

    expect(stateResetDecision())->toBe(DecisionReason::Granted);
    // A manual change of the tables, as after a restore, bypasses the pipeline: the state version does not move.
    CrmWorld::storage()->connection()->table('azg_role_grants')->where('subject_id', '1')->where('role', 'seller')->delete();
    expect(stateResetDecision())->toBe(DecisionReason::Granted);

    Event::fake([PanelStateTouched::class]);
    expect(Artisan::call('azguard:state:reset', ['panel' => 'crm']))->toBe(0);
    $after = CrmWorld::storage()->state('crm');

    expect($after->incarnation)->not->toBe($before->incarnation)
        ->and($after->version)->toBe($before->version + 1)
        ->and(Artisan::output())->toContain($after->incarnation, 'version '.$after->version)
        ->and(stateResetDecision())->toBe(DecisionReason::NotGranted)
        ->and(CrmWorld::storage()->table('audit_log')->orderByDesc('id')->first())
        ->toMatchObject(['type' => 'panel.touched', 'actor_reason' => 'azguard:state:reset']);
    Event::assertDispatched(PanelStateTouched::class, static fn (PanelStateTouched $event): bool => $event->reason === 'reset'
        && $event->previousVersion === $before->version && $event->state->incarnation === $after->incarnation
        && $event->actor?->reason === 'azguard:state:reset');
});

it('forgets the local permission sets of the panel', function (): void {
    W::panel();
    $access = AzGuard::panel('crm')->inTenant(W::tenant())->for(W::user(1));
    expect($access->permissionSet(W::project(1))->patterns())->not->toBe([]);
    CrmWorld::storage()->connection()->table('azg_role_grants')->where('subject_id', '1')->delete();
    $sets = app(PermissionSetCache::class);
    expect($access->permissionSet(W::project(1))->patterns())->not->toBe([]); // the local set of the old state

    expect(Artisan::call('azguard:state:reset', ['panel' => 'crm']))->toBe(0)
        ->and(app(PermissionSetCache::class))->toBe($sets)
        ->and($access->permissionSet(W::project(1))->patterns())->toBe([]);
});

it('refuses an unknown panel, a panel without a database writer and production without --force', function (): void {
    W::panel();
    $state = CrmWorld::storage()->state('crm');

    expect(Artisan::call('azguard:state:reset', ['panel' => 'shop']))->toBe(2);
    app()->instance('env', 'production');

    expect(Artisan::call('azguard:state:reset', ['panel' => 'crm']))->toBe(2)
        ->and(Artisan::output())->toContain('--force')
        ->and(CrmWorld::storage()->state('crm'))->toEqual($state)
        ->and(Artisan::call('azguard:state:reset', ['panel' => 'crm', '--force' => true]))->toBe(0)
        ->and(CrmWorld::storage()->state('crm')->incarnation)->not->toBe($state->incarnation);
});

it('refuses a reset inside an open transaction of the writer', function (): void {
    $panel = W::panel();
    $incarnation = CrmWorld::storage()->state('crm')->incarnation;

    expect(fn () => CrmWorld::storage()->connection()->transaction(static fn () => W::pipeline()->reset($panel)))
        ->toThrow(InvalidConfigurationException::class, 'own root transaction')
        ->and(CrmWorld::storage()->state('crm')->incarnation)->toBe($incarnation);
});
