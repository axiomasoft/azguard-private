<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('lists code roles read-only with assignment, super admin, scopes and the holders of the tenant', function (): void {
    $panel = W::panel();
    W::grant($panel, 'seller', 3, 1, until: new DateTimeImmutable('2026-10-06T12:30:00Z'));
    W::grant($panel, 'auditor', 2, null);
    Carbon::setTestNow('2026-10-06T13:00:00Z'); // Darya's seller grant on P1 has expired: not a third holder

    expect(Artisan::call('azguard:roles:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:1', '--json' => true]))->toBe(0);
    $roles = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $byKey = array_column($roles['roles'], null, 'key');

    // Tenant A: Anna seller P1, Boris seller P2, Anna analyst P2, Darya tenant-admin P1; tenant B is not counted.
    expect($roles['tenant'])->toBe('crm.organization:1')
        ->and($byKey['seller'])->toMatchArray(['editable' => false, 'assigned_by' => ['grant'], 'scope_required' => true, 'holders' => 2])
        ->and($byKey['analyst']['holders'])->toBe(1)
        ->and($byKey['auditor']['holders'])->toBe(1)
        ->and($byKey['root'])->toMatchArray(['grantable' => false, 'assigned_by' => [], 'super_admin' => false, 'holders' => 0]);
});

it('prints roles as a table and refuses a panel with tenants without --tenant', function (): void {
    W::panel();

    expect(Artisan::call('azguard:roles:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:2']))->toBe(0)
        ->and(Artisan::output())->toContain('seller', 'analyst', 'Holders')
        ->and(Artisan::call('azguard:roles:list', ['--panel' => 'crm']))->toBe(2)
        ->and(Artisan::call('azguard:roles:list', ['--panel' => 'crm', '--tenant' => 'crm.city:1']))->toBe(2);
});
