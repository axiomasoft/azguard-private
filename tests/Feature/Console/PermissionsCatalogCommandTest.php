<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => CrmWorld::seed());
afterEach(function (): void {
    app()->instance('env', 'testing');
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('creates a dynamic permission in the tenant and deletes it with its grants', function (): void {
    W::dynamicPanel();

    expect(Artisan::call('azguard:permissions:create', ['name' => 'crm:reports.export', '--label' => 'Export', '--group' => 'reports',
        '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(W::actionNames())->toBe(['crm.organization:1|reports.export'])
        ->and(collect(W::actions())->first())->toMatchArray(['label' => 'Export', 'group' => 'reports'])
        ->and(Artisan::call('azguard:permissions:grant', ['subject' => 'crm.user:2', 'permission' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(Artisan::call('azguard:permissions:delete', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(0)
        ->and(W::actions())->toBe([])
        ->and(W::rows('permission'))->toBe([]);
});

it('refuses dynamic permissions on a panel that does not declare them', function (): void {
    W::panel();

    expect(Artisan::call('azguard:permissions:create', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::output())->toContain('PanelNotWritableException');
});

it('needs --force to delete in production', function (): void {
    $panel = W::dynamicPanel();
    W::createAction($panel, 'reports.export');
    app()->instance('env', 'production');

    expect(Artisan::call('azguard:permissions:delete', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(Artisan::output())->toContain('--force')
        ->and(W::actionNames())->toBe(['crm.organization:1|reports.export'])
        ->and(Artisan::call('azguard:permissions:delete', ['name' => 'reports.export', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--force' => true]))->toBe(0)
        ->and(W::actions())->toBe([]);
});
