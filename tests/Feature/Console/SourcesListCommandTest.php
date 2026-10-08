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

it('redacts DSNs, URLs with credentials and secret-like names in source parameters', function (): void {
    config()->set('azguard.sources.crm-db', [
        'dsn' => 'pgsql://azguard:dsn-secret-pass@127.0.0.1/azguard', 'url' => 'https://user:url-secret-pass@db.example/az',
        'mirror' => 'mysql://reader:mirror-secret@10.0.0.2:3306/az?ssl=1', 'pass' => 'bare-pass-value', 'private' => 'private-key-material',
        'host' => '127.0.0.1', 'endpoint' => 'https://db.example/az',
    ]);
    app()->forgetInstance(AzGuardConfig::class);
    W::panel(sources: ['crm-db']);

    expect(Artisan::call('azguard:sources:list', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $parameters = array_column(json_decode($output, true, flags: JSON_THROW_ON_ERROR), null, 'name')['crm-db']['parameters'];

    expect($output)->not->toContain('dsn-secret-pass', 'url-secret-pass', 'mirror-secret', 'bare-pass-value', 'private-key-material')
        ->and($parameters)->toMatchArray(['dsn' => '[redacted]', 'pass' => '[redacted]', 'private' => '[redacted]', 'host' => '127.0.0.1',
            'url' => 'https://user:[redacted]@db.example/az', 'mirror' => 'mysql://reader:[redacted]@10.0.0.2:3306/az?ssl=1',
            'endpoint' => 'https://db.example/az']);
});
