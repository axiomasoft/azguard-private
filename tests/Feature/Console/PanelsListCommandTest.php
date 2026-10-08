<?php

declare(strict_types=1);

use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\Audit\AuditPlugin;
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

it('lists every panel with its writer, storage, tenants and plugins as JSON', function (): void {
    W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make(30)]));

    expect(Artisan::call('azguard:panels:list', ['--json' => true]))->toBe(0);
    $panels = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($panels, 'id'))->toBe(['crm', 'backoffice'])
        ->and($panels[0])->toMatchArray(['tenants' => 'required', 'writer' => 'database', 'storage' => 'default', 'plugins' => ['azguard/audit']])
        ->and($panels[0])->not->toHaveKeys(['settings', 'sources', 'schema']);
});

it('shows effective settings with their origin, the sources with their contribution and the schema', function (): void {
    W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make(30)])->cache(ttl: 120));

    expect(Artisan::call('azguard:panels:list', ['--settings' => true, '--sources' => true, '--schema' => true, '--json' => true]))->toBe(0);
    $crm = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];

    expect($crm['settings']['cache.ttl'])->toBe(['value' => 120, 'origin' => 'provider'])
        ->and($crm['settings']['consistency.state_refresh'])->toBe(['value' => 'check', 'origin' => 'provider'])
        ->and(array_column($crm['sources']['sources'], 'id'))->toContain('database')
        ->and(array_values(array_filter($crm['sources']['sources'], static fn (array $source): bool => $source['id'] === 'database'))[0]['capabilities'])
        ->toContain('StoresGrants')
        ->and($crm['sources']['plugins'])->toBe(['azguard/audit'])
        ->and(array_column($crm['schema']['roles'], 'key'))->toContain('seller', 'auditor')
        ->and(array_column($crm['schema']['permissions'], 'local'))->toContain('clients.view');
});

it('prints the same panels as tables', function (): void {
    W::panel();

    expect(Artisan::call('azguard:panels:list', ['--settings' => true, '--sources' => true]))->toBe(0);
    expect(Artisan::output())->toContain('crm', 'backoffice', 'Settings of crm', 'cache.ttl', 'Sources of crm', 'StoresGrants');
});
