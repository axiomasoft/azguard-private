<?php

declare(strict_types=1);

use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Internal\Checks\CacheStore;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelSettings;
use AzGuard\Panels\Reads;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Diagnostics\ProbeCheck;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Support\Facades\Artisan;

afterEach(fn () => CrmWorld::resetRuntime());

function securityPanel(?string $store, int $ttl = 60, array $checks = []): void
{
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()])->cache(store: $store, ttl: $ttl)->doctorChecks($checks)]);
}

it('distinguishes local, unsupported and redis stores without duplicating cache.store', function (): void {
    config(['cache.stores.local.driver' => 'array', 'cache.stores.disk.driver' => 'file', 'cache.stores.shared.driver' => 'redis',
        'azguard.cache.namespace' => null, 'azguard.cache.signing_key' => null, 'app.key' => null]);
    securityPanel(null);
    expect(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.signing'))->toBe([]);
    securityPanel('local');
    expect(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.signing'))->toBe([]);
    securityPanel('disk');
    expect(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.transport')))->toBe(['panel:test cache.transport warning']);
    securityPanel('shared', 4000);
    $findings = DoctorWorld::run(production: true);
    expect(DoctorWorld::summary(DoctorWorld::only($findings, 'cache.signing')))->toBe(['panel:test cache.signing error'])
        ->and(DoctorWorld::summary(DoctorWorld::only($findings, 'cache.ttl')))->toBe(['panel:test cache.ttl warning'])
        ->and(DoctorWorld::summary(DoctorWorld::only($findings, 'cache.redis')))->toBe(['panel:test cache.redis warning'])
        ->and(DoctorWorld::only($findings, 'cache.store'))->toBe([]);
    config(['azguard.cache.namespace' => 'deployment', 'azguard.cache.signing_key' => str_repeat('k', 32)]);
    expect(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.signing'))->toBe([]);
});

it('raises missing signing material from a development warning to a production error', function (): void {
    config(['cache.stores.shared.driver' => 'redis', 'azguard.cache.namespace' => null,
        'azguard.cache.signing_key' => null, 'app.key' => null]);
    securityPanel('shared');
    expect(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: false), 'cache.signing')))->toBe(['panel:test cache.signing warning'])
        ->and(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.signing')))->toBe(['panel:test cache.signing error']);
});

it('retains the null ttl error and detects invalid memory limits', function (): void {
    $settings = new PanelSettings(null, 'redis', null, 1, Reads::Primary, StateRefresh::Request, false, array_fill_keys(array_keys(PanelSettings::DEFAULTS), 'configure'));
    $panel = new Panel(id: 'test', label: 'test', default: false, settings: $settings, subjectModels: [User::class], pluginIds: [], tenantPolicy: null);
    expect(DoctorWorld::summary(CacheStore::check($panel)))->toBe(['panel:test cache.store error']);
    securityPanel(null);
    config(['azguard.cache.max_memo_entries' => 1025]);
    expect(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.memory')))->toBe(['core cache.memory error']);
});

it('redacts cache credentials in both command formats and reports production errors', function (): void {
    $key = str_repeat('secret-key-', 4);
    $dsn = 'redis://operator:secret-pass@example.test:6379';
    config(['cache.stores.shared.driver' => 'redis', 'database.redis.default.url' => $dsn,
        'azguard.cache.namespace' => null, 'azguard.cache.signing_key' => $key, 'app.key' => 'application-secret-key']);
    securityPanel('shared', checks: [new ProbeCheck('probe.secret', static fn (): array => [DoctorFinding::warning('probe.secret', $key.' '.$dsn.' secret-pass application-secret-key')])]);
    foreach ([false, true] as $json) {
        expect(Artisan::call('azguard:doctor', ['--production' => true, '--json' => $json]))->toBe(1)
            ->and(Artisan::output())->toContain('cache.signing', '[redacted]')
            ->not->toContain($key, $dsn, 'secret-pass', 'application-secret-key');
    }
});

it('reports configured unsafe Redis client transforms without opening a connection', function (array $options, bool $unsafe): void {
    config(['cache.stores.shared' => ['driver' => 'redis', 'connection' => 'offline'],
        'database.redis.client' => 'phpredis', 'database.redis.offline' => ['host' => 'invalid.test'],
        'azguard.cache.namespace' => 'deployment', 'azguard.cache.signing_key' => str_repeat('k', 32)]);
    config($options);
    securityPanel('shared');
    expect(DoctorWorld::summary(DoctorWorld::only(DoctorWorld::run(production: true), 'cache.transport')))
        ->toBe($unsafe ? ['panel:test cache.transport warning'] : []);
})->with([
    [['database.redis.options.serializer' => 1], true],
    [['database.redis.offline.compression' => 1], true],
    [['database.redis.offline.options.serializer' => 1], true],
    [['database.redis.options.serializer' => 1, 'database.redis.offline.options.serializer' => 0], false],
    [['database.redis.options.serializer' => 1, 'database.redis.client' => 'predis'], false],
    [[], false],
]);
