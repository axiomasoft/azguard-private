<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Sources\SourceManager;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    CrmWorld::seed();
    config()->set('azguard.sources', [
        'crm-db' => ['connection' => 'testing', 'password' => 'source-secret', 'nested' => ['api_token' => 'nested-secret', 'region' => 'R1']],
        'unregistered' => ['region' => 'R2'],
    ]);
    app()->forgetInstance(AzGuardConfig::class);
    app(SourceManager::class)->extend('crm-db', static fn () => CrmWorld::database());
});
afterEach(function (): void {
    app()->forgetInstance(SourceManager::class);
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('lists factory names with class, redacted parameters and the panels that use them', function (): void {
    W::panel(sources: ['crm-db']);

    expect(Artisan::call('azguard:sources:list', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $sources = array_column(json_decode($output, true, flags: JSON_THROW_ON_ERROR), null, 'name');

    expect($output)->not->toContain('source-secret', 'nested-secret')
        ->and($sources['crm-db'])->toBe([
            'name' => 'crm-db', 'registered' => true, 'class' => null,
            'parameters' => ['connection' => 'testing', 'password' => '[redacted]', 'nested' => ['api_token' => '[redacted]', 'region' => 'R1']],
            'panels' => ['crm'],
        ])
        ->and($sources['unregistered'])->toMatchArray(['registered' => false, 'class' => null, 'panels' => []]);
});

it('prints the sources as a table without secrets', function (): void {
    W::panel(sources: ['crm-db']);

    expect(Artisan::call('azguard:sources:list'))->toBe(0);
    expect(Artisan::output())->toContain('crm-db', 'extend()', 'not registered', '[redacted]')->not->toContain('source-secret', 'nested-secret');
});
