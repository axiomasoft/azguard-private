<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    CrmWorld::seed();
    W::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('grants and revokes a direct permission or pattern as the system actor', function (string $permission, string $stored): void {
    expect(Artisan::call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => $permission, '--panel' => 'crm',
        '--tenant' => 'crm.organization:1', '--on' => 'crm.project:1']))->toBe(0);
    $row = collect(W::rows('permission'))->firstWhere('subject_id', '3');

    expect($row)->toMatchArray(['permission' => $stored, 'context_key' => 'crm.project:1', 'actor_reason' => 'azguard:permissions:grant'])
        ->and(Artisan::call('azguard:permissions:revoke', ['subject' => 'crm.user:3', 'permission' => $permission, '--panel' => 'crm',
            '--tenant' => 'crm.organization:1', '--on' => 'crm.project:1']))->toBe(0)
        ->and(collect(W::rows('permission'))->firstWhere('subject_id', '3'))->toBeNull();
})->with([
    'local name' => ['clients.view', 'clients.view'],
    'name with the panel prefix' => ['crm.clients.update', 'clients.update'],
    'full name' => ['crm:clients.view_any', 'clients.view_any'],
    'pattern' => ['clients.*', 'clients.*'],
]);

it('R63 refuses an exact PolicyOnly grant through the CLI without a write or a version', function (): void {
    $version = W::version();

    expect(Artisan::call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => 'clients.view_own_profile', '--panel' => 'crm',
        '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::output())->toContain('PermissionNotGrantableException')
        ->and(W::rows('permission'))->toBe([])
        ->and(W::version())->toBe($version);
});

it('V64 refuses a permission of another panel than --panel', function (): void {
    expect(Artisan::call('azguard:permissions:grant', ['subject' => 'crm.user:3', 'permission' => 'backoffice:clients.view', '--panel' => 'crm',
        '--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(W::rows('permission'))->toBe([]);
});
