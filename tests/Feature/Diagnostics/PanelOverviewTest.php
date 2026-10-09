<?php

declare(strict_types=1);

use AzGuard\Diagnostics\PanelOverview;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\Audit\AuditPlugin;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Facades\Artisan;

/*
 * The read-model that `azguard:panels:list` and the panels page print: one source, the same data.
 */

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

it('describes every panel and adds only the parts that are asked for', function (): void {
    W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make(30)]));
    $overview = app(PanelOverview::class);

    $plain = $overview->all();
    $full = $overview->all(settings: true, sources: true, schema: true);

    expect(array_column($plain, 'id'))->toBe(['crm', 'backoffice'])
        ->and($plain[0])->toMatchArray(['tenants' => 'required', 'writer' => 'database', 'storage' => 'default', 'plugins' => ['azguard/audit']])
        ->and($plain[0])->not->toHaveKeys(['settings', 'sources', 'schema'])
        ->and($full[0])->toHaveKeys(['settings', 'sources', 'schema'])
        ->and($full[0]['sources']['plugins'])->toBe(['azguard/audit'])
        ->and($overview->panel(app(PanelRegistry::class)->get('crm'), sources: true))->toHaveKey('sources')->not->toHaveKey('settings');
});

it('gives the data that the command prints as JSON', function (): void {
    W::panel();

    Artisan::call('azguard:panels:list', ['--settings' => true, '--sources' => true, '--schema' => true, '--json' => true]);
    $printed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $read = json_decode(json_encode(app(PanelOverview::class)->all(true, true, true), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($read)->toBe($printed);
});
