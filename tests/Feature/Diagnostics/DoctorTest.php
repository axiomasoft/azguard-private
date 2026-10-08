<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Sources\ChecksHealth;
use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Severity;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Diagnostics\CheckingPlugin;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Diagnostics\ExplodingCheck;
use AzGuard\Tests\Fixtures\Diagnostics\HealthSource;
use AzGuard\Tests\Fixtures\Diagnostics\ProbeCheck;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    ProbeCheck::$panels = [];
});

/**
 * @param  Closure(PanelBuilder): mixed  $more
 */
function doctorPanel(?Closure $more = null): void
{
    DoctorWorld::panels([TestPanel::class => static function (PanelBuilder $panel) use ($more): void {
        $panel->for(User::class)->permissions([OrderPermission::class, DatabaseSource::make()]);

        if ($more !== null) {
            $more($panel);
        }
    }]);
}

it('finds nothing in a migrated panel without problems', function (): void {
    DoctorWorld::migrate();
    doctorPanel();

    expect(DoctorWorld::run())->toBe([]);
});

it('accepts doctor checks as objects or classes and refuses anything else when the panel compiles', function (mixed $check): void {
    expect(fn () => doctorPanel(static fn (PanelBuilder $panel) => $panel->doctorChecks([$check])))
        ->toThrow(DefinitionException::class, 'DoctorCheck');
})->with([
    'a class that is not a check' => [stdClass::class],
    'an object that is not a check' => [new stdClass],
    'a missing class' => ['App\\Missing\\Check'],
]);

it('runs the checks of sources, the panel and its plugins for the panel, each in its scope', function (): void {
    DoctorWorld::migrate();
    doctorPanel(static fn (PanelBuilder $panel) => $panel
        ->permissions([new HealthSource([ProbeCheck::finding('health.directory', 'Directory is offline.')])])
        ->doctorChecks([ProbeCheck::finding('panel.custom'), ExplodingCheck::class])
        ->plugins([new CheckingPlugin]));

    $findings = DoctorWorld::run();

    expect(DoctorWorld::summary($findings))->toBe([
        'panel:test health.directory error',
        'panel:test panel.custom error',
        'panel:test probe.exploding.failed error',
        'plugin:acme/checking acme.folders error',
    ])->and(ProbeCheck::$panels)->toBe(['test', 'test', 'test'])
        ->and(DoctorWorld::only($findings, 'acme.folders')[0]->details)->toBe(['panel' => 'test']);
});

it('reports a failing check as <key>.failed with the exception class only and runs every other check', function (): void {
    doctorPanel(static fn (PanelBuilder $panel) => $panel
        ->permissions([new HealthSource([new ProbeCheck('health.broken', static fn () => throw new RuntimeException('dsn=mysql://root:Hidden-Pw-77@10.0.0.1'))])])
        ->doctorChecks([ExplodingCheck::class, ProbeCheck::finding('probe.after')]));

    $findings = DoctorWorld::run();
    $failed = DoctorWorld::only($findings, 'probe.exploding.failed')[0];
    $json = json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings), JSON_THROW_ON_ERROR);

    expect($failed->severity)->toBe(Severity::Error)
        ->and($failed->message)->toContain(RuntimeException::class)
        ->and($failed->details)->toBe(['exception' => RuntimeException::class])
        ->and(DoctorWorld::only($findings, 'health.broken.failed'))->toHaveCount(1)
        ->and(DoctorWorld::only($findings, 'probe.after'))->toHaveCount(1)
        // The storage is not migrated: the core checks still ran after the failures.
        ->and(DoctorWorld::only($findings, 'storage.migrated'))->toHaveCount(1)
        ->and($json)->not->toContain('Exploding-Secret-42')->not->toContain('db.internal')->not->toContain('Hidden-Pw-77')->not->toContain('10.0.0.1');
});

it('reports a check that yields something other than a finding, or declares a malformed key, as failed', function (): void {
    DoctorWorld::migrate();
    doctorPanel(static fn (PanelBuilder $panel) => $panel->doctorChecks([
        new ProbeCheck('probe.strange', static fn (): array => ['not a finding']),
        new ProbeCheck('Not A Key', static fn (): array => []),
        ProbeCheck::finding('probe.after'),
    ]));

    expect(DoctorWorld::summary(DoctorWorld::run()))->toBe([
        'panel:test doctor.check.failed error',
        'panel:test probe.after error',
        'panel:test probe.strange.failed error',
    ]);
});

it('reports a source whose doctorChecks() throws and still runs the panel checks', function (): void {
    DoctorWorld::migrate();
    $source = new class implements ChecksHealth
    {
        public function id(): string
        {
            return 'faulty';
        }

        public function doctorChecks(): array
        {
            throw new LogicException('cannot list checks');
        }
    };
    doctorPanel(static fn (PanelBuilder $panel) => $panel->permissions([$source])->doctorChecks([ProbeCheck::finding('probe.after')]));

    expect(DoctorWorld::summary(DoctorWorld::run()))->toBe(['panel:test panels.sources.failed error', 'panel:test probe.after error']);
});

it('orders findings by scope, key and message', function (): void {
    DoctorWorld::migrate();
    doctorPanel(static fn (PanelBuilder $panel) => $panel->doctorChecks([
        new ProbeCheck('zeta.check', static fn (): array => [DoctorFinding::warning('zeta.check', 'b'), DoctorFinding::warning('zeta.check', 'a')]),
        new ProbeCheck('alpha.check', static fn (): array => [DoctorFinding::error('alpha.check', 'z', 'storage:default')]),
    ]));

    expect(array_map(static fn (DoctorFinding $finding): string => $finding->scope.' '.$finding->key.' '.$finding->message, DoctorWorld::run()))->toBe([
        'panel:test zeta.check a',
        'panel:test zeta.check b',
        'storage:default alpha.check z',
    ]);
});

it('selects panels, storages and the panels or storages related to them', function (): void {
    DoctorWorld::migrate();
    DoctorWorld::panels([
        AdminPanel::class => static fn (PanelBuilder $panel) => $panel->for([User::class, Vendor::class])->permissions([OrderPermission::class, DatabaseSource::make()]),
        CabinetPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([InvoicePermission::class]),
    ]);
    $doctor = app(Doctor::class);
    $ids = static fn (DoctorContext $context): array => [
        array_map(static fn ($panel): string => $panel->id(), $context->panels()),
        array_map(static fn ($storage): string => $storage->id(), $context->storages()),
    ];

    expect($ids($doctor->context()))->toBe([['admin', 'cabinet'], ['default']])
        ->and($ids($doctor->context(['cabinet'])))->toBe([['cabinet'], []])
        ->and($ids($doctor->context(['admin'])))->toBe([['admin'], ['default']])
        ->and($ids($doctor->context(storages: ['default'])))->toBe([['admin'], ['default']])
        ->and($ids($doctor->context(['cabinet'], ['default'])))->toBe([['cabinet'], ['default']])
        ->and($doctor->context(production: true)->isProduction())->toBeTrue()
        ->and(fn () => $doctor->context(['ghost']))->toThrow(UnknownPanelException::class)
        ->and(fn () => $doctor->context(storages: ['ghost']))->toThrow(InvalidConfigurationException::class);
});

it('V104 never prints passwords, DSNs or source secrets in findings, details or JSON', function (): void {
    config([
        'database.connections.secondary.password' => 'V104-Db-Password',
        'database.connections.secondary.url' => 'pgsql://azguard:V104-Url-Pass@db.example:5432/app',
        'database.connections.unreachable' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'azguard_test',
            'username' => 'azguard', 'password' => 'V104-Remote-Pass', 'connect_timeout' => 1],
        'azguard.storages' => ['default' => [], 'remote' => ['connection' => 'unreachable']],
        'azguard.sources.ldap' => ['host' => 'ldap.example', 'password' => 'V104-Ldap-Secret', 'token' => 'V104-Ldap-Token'],
    ]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    DoctorWorld::migrate();
    doctorPanel(static fn (PanelBuilder $panel) => $panel->doctorChecks([new ProbeCheck('probe.leaky', static fn (): array => [
        DoctorFinding::warning('probe.leaky', 'Bound to ldap with V104-Ldap-Secret via pgsql://azguard:V104-Url-Pass@db.example:5432/app and V104-Db-Password',
            details: ['password' => 'V104-Db-Password', 'dsn' => 'pgsql://x', 'note' => 'token V104-Ldap-Token', 'hosts' => ['V104-Remote-Pass', 'ok']]),
    ])]));

    $findings = DoctorWorld::run();
    Artisan::call('azguard:doctor', ['--json' => true]);
    $output = Artisan::output().json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings), JSON_THROW_ON_ERROR);
    $leaky = DoctorWorld::only($findings, 'probe.leaky')[0];

    foreach (['V104-Db-Password', 'V104-Url-Pass', 'V104-Remote-Pass', 'V104-Ldap-Secret', 'V104-Ldap-Token'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect($leaky->message)->toContain(DoctorFinding::REDACTED)
        ->and($leaky->details)->toBe(['dsn' => DoctorFinding::REDACTED, 'hosts' => [DoctorFinding::REDACTED, 'ok'],
            'note' => 'token '.DoctorFinding::REDACTED, 'password' => DoctorFinding::REDACTED])
        ->and(DoctorWorld::summary(DoctorWorld::only($findings, 'storage.migrated')))->toBe(['storage:remote storage.migrated error'])
        ->and(DoctorWorld::only($findings, 'storage.migrated')[0]->message)->toContain('cannot be reached ('.QueryException::class.')');
});

it('V104 removes a short password where it stands as a value of its own, not inside other words', function (): void {
    config(['database.connections.secondary.password' => 'pw', 'azguard.sources.ldap' => ['password' => 'pw']]);
    app()->forgetInstance(AzGuardConfig::class);
    DoctorWorld::migrate();
    doctorPanel(static fn (PanelBuilder $panel) => $panel->doctorChecks([new ProbeCheck('probe.leaky', static fn (): array => [
        DoctorFinding::warning('probe.leaky', 'password pw via mysql://root:pw@db, pwd stays', details: ['note' => 'pw and pw2']),
    ])]));

    $leaky = DoctorWorld::only(DoctorWorld::run(), 'probe.leaky')[0];

    expect($leaky->message)->toBe('password [redacted] via mysql://root:[redacted]@db, pwd stays')
        ->and($leaky->details)->toBe(['note' => '[redacted] and pw2']);
});

it('reports a storage whose connection is not configured instead of failing the run', function (): void {
    config(['azguard.storages' => ['default' => [], 'ghost' => ['connection' => 'no_such_connection']]]);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(StorageRegistry::class);
    // A panel whose database source used the storage would not boot; this one stores nothing.
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([OrderPermission::class])
        ->doctorChecks([ProbeCheck::finding('probe.after')])]);

    $findings = DoctorWorld::run();
    $config = DoctorWorld::only($findings, 'config.valid');

    expect(DoctorWorld::summary($config))->toBe(['core config.valid error'])
        ->and($config[0]->message)->toContain('azguard.storages.ghost', 'no_such_connection')
        ->and(DoctorWorld::only($findings, 'probe.after'))->toHaveCount(1)
        ->and(Artisan::call('azguard:doctor', ['--json' => true]))->toBe(1);
});
