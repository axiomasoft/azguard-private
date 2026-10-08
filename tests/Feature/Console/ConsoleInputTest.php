<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * Rules every AzGuard command shares: the subject forms, the panel only through the resolver, a tenant for a panel with
 * tenants, exit code 2 for invalid input and 1 for a refused operation.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    W::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('takes a bare subject id when the panels have one subject model', function (): void {
    expect(Artisan::call('azguard:grants:list', ['subject' => '2', '--panel' => 'crm', '--tenant' => 'crm.organization:1', '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['subject'])->toBe('crm.user:2');
});

it('V64 never picks a panel itself: without --panel the resolver must decide or the command fails', function (): void {
    // crm and backoffice both accept crm.user and neither is the default panel of the model.
    expect(Artisan::call('azguard:grants:list', ['subject' => 'crm.user:1', '--tenant' => 'crm.organization:1']))->toBe(2)
        ->and(Artisan::output())->toContain('crm', 'backoffice')
        ->and(Artisan::call('azguard:catalog:list', ['--tenant' => 'crm.organization:1']))->toBe(2);
});

it('refuses invalid input with exit code 2', function (array $arguments, string $message): void {
    expect(Artisan::call('azguard:permissions:show', [...['subject' => 'crm.user:1', '--panel' => 'crm', '--tenant' => 'crm.organization:1'], ...$arguments]))->toBe(2)
        ->and(Artisan::output())->toContain($message);
})->with([
    'subject without id' => [['subject' => 'crm.user:'], 'type:id'],
    'unknown subject type' => [['subject' => 'shop.customer:1'], 'not a subject of panel'],
    'tenant without type' => [['--tenant' => '1'], 'type:id'],
    'tenant of another type' => [['--tenant' => 'crm.city:1'], 'crm.city'],
    'scope without type' => [['--on' => 'P1'], 'type:id'],
    'unknown panel' => [['--panel' => 'shop'], 'shop'],
    'empty panel' => [['--panel' => ' '], '--panel needs a value'],
]);

it('requires --tenant for a panel with tenants before anything is read', function (): void {
    expect(Artisan::call('azguard:grants:list', ['subject' => 'crm.user:1', '--panel' => 'crm']))->toBe(2)
        ->and(Artisan::output())->toContain('pass --tenant=type:id');
});

it('reports an unexpected failure with exit code 1 and its message only with -v', function (): void {
    CrmWorld::storage()->connection()->getSchemaBuilder()->drop('azg_role_grants');

    expect(Artisan::call('azguard:roles:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:1']))->toBe(1)
        ->and(Artisan::output())->toContain('QueryException', 'Run it with -v')->not->toContain('azg_role_grants')
        ->and(Artisan::call('azguard:roles:list', ['--panel' => 'crm', '--tenant' => 'crm.organization:1', '-v' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('azg_role_grants');
});
