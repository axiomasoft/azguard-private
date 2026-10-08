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

it('lists static and dynamic permissions of the tenant with authority, sources and policy', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'reports.export', 1, 'Export', 'reports');
    W::createAction($panel, 'reports.secret', 2);

    expect(Artisan::call('azguard:catalog:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:1', '--json' => true]))->toBe(0);
    $catalog = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $permissions = array_column($catalog['permissions'], null, 'local');

    expect($catalog['panel'])->toBe('crm')
        ->and($catalog['tenant'])->toBe('crm.organization:1')
        ->and($permissions)->toHaveKeys(['clients.view', 'clients.view_own_profile', 'reports.export'])->not->toHaveKey('reports.secret')
        ->and($permissions['clients.view'])->toMatchArray(['dynamic' => false, 'authority' => 'grants'])
        ->and($permissions['clients.view_own_profile']['authority'])->toBe('policy')
        ->and($permissions['reports.export'])->toMatchArray(['dynamic' => true, 'label' => 'Export', 'group' => 'reports']);
});

it('prints the catalog as a table', function (): void {
    W::panel();

    expect(Artisan::call('azguard:catalog:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0);
    expect(Artisan::output())->toContain('clients.view', 'static', 'Policy');
});

it('refuses a panel with tenants without --tenant and an unresolved panel', function (): void {
    W::panel();

    expect(Artisan::call('azguard:catalog:list', ['--panel' => 'crm']))->toBe(2)
        ->and(Artisan::output())->toContain('--tenant')
        ->and(Artisan::call('azguard:catalog:list', ['--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(Artisan::call('azguard:catalog:list', ['--panel' => 'missing', '--tenant' => 'crm.organization:1']))->toBe(2);
});
