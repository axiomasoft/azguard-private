<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
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

/** Boris (user 2) on his own client 6 in a fresh request scope. */
function renameKeyDecision(): DecisionReason
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return CrmWorld::decide(app(PanelRegistry::class)->get('crm'), 6, user: 2)->reason;
}

it('V10 R66 keeps a former key without authority, shows the plan on a dry run and moves it with rename-key', function (): void {
    W::panel();
    M::stored('role', 'inspector', 2);
    $collision = M::stored('role', 'inspector', 1, until: '2027-06-01 00:00:00');
    W::grant(app(PanelRegistry::class)->get('crm'), 'auditor', 1, null);
    $version = W::version();

    expect(renameKeyDecision())->toBe(DecisionReason::NotGranted)
        ->and(Artisan::call('azguard:roles:rename-key', ['role' => 'crm:inspector', 'new' => 'auditor', '--tenant' => 'crm.organization:1', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('2 grant(s) of inspector would move', 'nothing was written', $collision, 'role:')
        ->and(W::version())->toBe($version)
        ->and(W::keys())->toContain('crm.organization:1|inspector|2|global|manual');

    Event::fake([RoleGrantUpdated::class]);

    expect(Artisan::call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => AuditorRole::class, '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(W::keys())->not->toContain('crm.organization:1|inspector|2|global|manual', 'crm.organization:1|inspector|1|global|manual')
        ->and(W::keys())->toContain('crm.organization:1|auditor|2|global|manual')
        ->and(array_count_values(W::keys())['crm.organization:1|auditor|1|global|manual'])->toBe(1)
        ->and(renameKeyDecision())->toBe(DecisionReason::Granted);
    Event::assertDispatched(RoleGrantUpdated::class, static fn (RoleGrantUpdated $event): bool => $event->actor?->reason === 'azguard:roles:rename-key');
});

it('refuses a key that the role does not list as former, a panel with tenants without --tenant and production without --force', function (): void {
    W::panel();
    M::stored('role', 'inspector', 2);
    $version = W::version();

    expect(Artisan::call('azguard:roles:rename-key', ['role' => 'seller', 'new' => 'auditor', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', '--panel' => 'crm']))->toBe(2);
    app()->instance('env', 'production');

    expect(Artisan::call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(Artisan::output())->toContain('--force')
        ->and(Artisan::call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--dry-run' => true]))->toBe(0)
        ->and(W::version())->toBe($version)
        ->and(Artisan::call('azguard:roles:rename-key', ['role' => 'inspector', 'new' => 'auditor', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--force' => true]))->toBe(0)
        ->and(W::keys())->toContain('crm.organization:1|auditor|2|global|manual');
});
