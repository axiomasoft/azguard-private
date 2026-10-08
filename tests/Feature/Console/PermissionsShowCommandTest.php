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

it('shows the roles and Grants permissions of the subject in the selected scope', function (): void {
    W::panel();

    expect(Artisan::call('azguard:permissions:show', ['subject' => 'crm.user:1', '--panel' => 'crm', '--tenant' => 'crm.organization:1',
        '--on' => 'crm.project:1', '--json' => true]))->toBe(0);
    $shown = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($shown)->toMatchArray(['panel' => 'crm', 'tenant' => 'crm.organization:1', 'subject' => 'crm.user:1', 'context' => 'crm.project:1',
        'super_admin' => false, 'roles' => ['seller']])
        ->and($shown['permissions'])->toBe(['crm.clients.update', 'crm.clients.view', 'crm.clients.view_any']);
});

it('shows another tenant only with its --tenant and prints text', function (): void {
    W::panel();

    expect(Artisan::call('azguard:permissions:show', ['subject' => 'crm.user:1', '--panel' => 'crm', '--tenant' => 'crm.organization:2', '--on' => 'crm.project:4']))->toBe(0)
        ->and(Artisan::output())->toContain('Roles: analyst', 'Super admin: no')
        ->and(Artisan::call('azguard:permissions:show', ['subject' => 'crm.user:1', '--panel' => 'crm']))->toBe(2);
});
