<?php

declare(strict_types=1);

use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\AuditPlugin;
use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Configuration\ConfigSections;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Activation\ActivationWorld as World;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
 * The activation matrix (PLAN1 P3.12, p3-execution#activation): two panels over one storage, a module active on one and
 * absent from the other. Every row has a negative control that runs the same measure with the module where it must not be
 * and shows the assertion would fail, so a row cannot pass by measuring nothing.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    EventWorld::listen();
});

afterEach(function (): void {
    World::reset();
    CrmWorld::resetRuntime();
    EventWorld::reset();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** Boot-time work of a core-only panel: decide, grant, the visible list, revoke and the doctor. */
function activationRoundTrip(PanelRegistry $registry): array
{
    $alpha = $registry->get('alpha');
    $before = World::decide($alpha, 2)->allowed();
    World::grant($alpha, 'support', 2, null);
    $granted = World::decide($alpha, 2)->allowed();

    // A tenant panel without a scope declaration has no exact list (`resource_owner_adapter`); the call still runs.
    try {
        $visible = app(Authorizer::class)->visibleTo($alpha, Client::query(), SubjectRef::of('crm.user', 2), 'clients.view_any',
            AccessScope::in(TenantRef::of('crm.organization', 1)))->orderBy('id')->pluck('id')->all();
    } catch (VisibilityNotSupportedException $error) {
        $visible = $error->reason;
    }
    World::revoke($alpha, 'support', 2, null);
    $revoked = World::decide($alpha, 2)->allowed();
    $doctor = Artisan::call('azguard:doctor', ['--json' => true]);

    return ['decisions' => [$before, $granted, $revoked], 'visible' => $visible, 'doctor' => $doctor];
}

it('row 1: core only boots, checks, grants, lists and revokes with no module service, no module query and no journal table', function (): void {
    $registry = World::compile();
    $result = [];
    $costs = World::costsOf(function () use ($registry, &$result): void {
        $result = activationRoundTrip($registry);
    });

    expect($result['decisions'])->toBe([false, true, false])
        ->and($result['visible'])->toBe('resource_owner_adapter')
        ->and($costs)->toBe([])
        ->and(Schema::connection(CrmWorld::storage()->connectionName())->hasTable(CrmWorld::storage()->prefix().'audit_log'))->toBeFalse()
        ->and($registry->get('alpha')->scopeDeclaration())->toBeNull()
        ->and($registry->get('beta')->scopeDeclaration())->toBeNull()
        ->and($registry->get('alpha')->pluginIds())->toBe([]);
});

it('row 1 control: the same measure sees Audit and Scopes when a panel activates them', function (): void {
    AuditTable::create();
    $registry = World::compile([AuditPlugin::make(), ScopesPlugin::inherit(ProjectScope::make())]);
    $costs = World::costsOf(function () use ($registry): void {
        activationRoundTrip($registry);
    });

    expect($costs)->toContain('module-query audit_log')
        ->and(array_filter($costs, static fn (string $cost): bool => str_starts_with($cost, 'module-service AzGuard\\Scopes\\') || str_starts_with($cost, 'module-service AzGuard\\Audit\\')))->not->toBe([])
        ->and($registry->get('alpha')->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit);
});

it('row 2: Scopes active on A, not on B: A interprets mode and aliases, B refuses a scope and its stored scoped rows are inert', function (): void {
    $registry = World::compile([ScopesPlugin::inherit(ProjectScope::make())], scopedRoles: true);
    [$alpha, $beta] = [$registry->get('alpha'), $registry->get('beta')];
    CrmWorld::assign('analyst', 2, 1, panel: 'alpha');
    CrmWorld::assign('analyst', 2, 1, panel: 'beta');
    $project = CrmWorld::scope(1, 1);

    $onAlpha = World::decide($alpha, 2, $project);
    $onBeta = World::decide($beta, 2, $project);
    $wideOnBeta = World::decide($beta, 2);

    expect($alpha->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit)->and($beta->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::None)->and($beta->scopeDeclaration()?->evaluator)->toBeNull()
        ->and($onAlpha->allowed())->toBeTrue()
        ->and($onBeta->allowed())->toBeFalse()->and($onBeta->reason)->toBe(DecisionReason::AssignmentScopeNotAccepted)
        ->and($wideOnBeta->allowed())->toBeFalse('a stored scoped row of B authorizes a tenant-wide request');
});

it('row 2 control: a tenant-wide row of B is honored, and B with the module accepts the scope', function (): void {
    CrmWorld::assign('support', 2, 1, panel: 'beta', scope: CrmWorld::scope(1));
    $without = World::compile([ScopesPlugin::inherit(ProjectScope::make())], scopedRoles: true);
    $wide = World::decide($without->get('beta'), 2);
    CrmWorld::assign('analyst', 2, 1, panel: 'beta');
    $with = World::compile([], [ScopesPlugin::inherit(ProjectScope::make())], scopedRoles: true);

    expect($wide->allowed())->toBeTrue()
        ->and(World::decide($with->get('beta'), 2, CrmWorld::scope(1, 1))->reason)->not->toBe(DecisionReason::AssignmentScopeNotAccepted);
});

it('row 3: Audit active on A, not on B, one storage: A journals the event it dispatched, B writes no row', function (): void {
    AuditTable::create();
    $registry = World::compile([AuditPlugin::make()]);
    $granted = World::grant($registry->get('alpha'), 'support', 2, null);
    $rows = EventWorld::auditRows();
    World::grant($registry->get('beta'), 'support', 2, null);

    expect($rows)->toHaveCount(1)->and($rows[0]['panel'])->toBe('alpha')->and($rows[0]['event_id'])->toBe($granted->effects[0]->eventId)
        ->and($rows[0]['event_id'])->toBe(EventWorld::events()[0]->eventId)
        ->and(EventWorld::auditRows())->toHaveCount(1)->and(EventWorld::types())->toBe(['role.granted', 'role.granted']);
});

it('row 3 control: with Audit on B too, B writes its row', function (): void {
    AuditTable::create();
    $registry = World::compile([AuditPlugin::make()], [AuditPlugin::make()]);
    World::grant($registry->get('alpha'), 'support', 2, null);
    World::grant($registry->get('beta'), 'support', 2, null);

    expect(array_column(EventWorld::auditRows(), 'panel'))->toBe(['alpha', 'beta']);
});

it('row 4: two panels with different options keep their own options, modes and fingerprints', function (): void {
    $compile = static fn (int $alphaDays, int $betaDays): PanelRegistry => World::compile(
        [AuditPlugin::make($alphaDays), ScopesPlugin::inherit(ProjectScope::make())],
        [AuditPlugin::make($betaDays), ScopesPlugin::isolated(ProjectScope::make())],
    );
    $registry = $compile(30, 365);

    expect($registry->get('alpha')->options(AuditOptions::class)?->retentionDays)->toBe(30)
        ->and($registry->get('beta')->options(AuditOptions::class)?->retentionDays)->toBe(365)
        ->and($registry->get('alpha')->options(AuditOptions::class))->not->toBe($registry->get('beta')->options(AuditOptions::class))
        ->and($registry->get('alpha')->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit)
        ->and($registry->get('beta')->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Isolated)
        ->and($registry->fingerprint('alpha'))->not->toBe($registry->fingerprint('beta'));
});

it('row 4 control: a changed retention changes only the fingerprint of its own panel', function (): void {
    $fingerprints = static function (int $alphaDays, int $betaDays): array {
        $registry = World::compile([AuditPlugin::make($alphaDays)], [AuditPlugin::make($betaDays)]);

        return [$registry->fingerprint('alpha'), $registry->fingerprint('beta')];
    };

    expect($fingerprints(30, 90)[0])->not->toBe($fingerprints(60, 90)[0])
        ->and($fingerprints(30, 90)[1])->toBe($fingerprints(60, 90)[1])
        ->and($fingerprints(30, 90)[0])->toBe($fingerprints(30, 365)[0]);
});

it('row 5: a module removed after use leaves its rows; scopes fail closed, journaling stops, revoke and prune still work', function (): void {
    AuditTable::create();
    Carbon::setTestNow('2026-10-06T12:00:00Z');
    $used = World::compile([AuditPlugin::make(), ScopesPlugin::inherit(ProjectScope::make())], scopedRoles: true);
    World::grant($used->get('alpha'), 'analyst', 2, 1);
    World::grant($used->get('alpha'), 'auditor', 3, null, until: new DateTimeImmutable('2026-10-06T12:10:00Z'));
    $journal = EventWorld::auditRows();
    $scoped = W::rows('role');

    // The application drops both modules from the panel; the stored rows stay.
    $removed = World::compile(scopedRoles: true);
    $alpha = $removed->get('alpha');
    $scopedDecision = World::decide($alpha, 2, CrmWorld::scope(1, 1));
    World::grant($alpha, 'auditor', 1, null);

    expect($journal)->toHaveCount(2)->and($scoped)->toHaveCount(7)->and(W::rows('role'))->toHaveCount(8)
        ->and($scopedDecision->allowed())->toBeFalse()->and($scopedDecision->reason)->toBe(DecisionReason::AssignmentScopeNotAccepted)
        ->and(EventWorld::auditRows())->toHaveCount(2, 'journaling continued after the module was removed');

    Carbon::setTestNow('2026-10-06T13:00:00Z');
    expect(Artisan::call('azguard:grants:prune'))->toBe(0)->and(Artisan::output())->toContain('1 expired grant(s) removed');
    World::revoke($alpha, 'auditor', 1, null);
    expect(array_filter(W::rows('role'), static fn (array $row): bool => $row['role'] === 'auditor'))->toBe([])
        ->and(EventWorld::auditRows())->toHaveCount(2)
        ->and(array_filter(W::rows('role'), static fn (array $row): bool => $row['context_id'] === '1' && $row['role'] === 'analyst' && $row['subject_id'] === '2'))->toHaveCount(1, 'the scoped row was deleted');
});

it('row 5 control: while the modules stay attached the same grant is journaled and the scoped row is honored', function (): void {
    AuditTable::create();
    $kept = World::compile([AuditPlugin::make(), ScopesPlugin::inherit(ProjectScope::make())], scopedRoles: true);
    World::grant($kept->get('alpha'), 'analyst', 2, 1);
    World::grant($kept->get('alpha'), 'auditor', 1, null);

    expect(EventWorld::auditRows())->toHaveCount(2)
        ->and(World::decide($kept->get('alpha'), 2, CrmWorld::scope(1, 1))->allowed())->toBeTrue();
});

it('row 6: DevTools outside the console registers no command but still owns the scaffold section', function (): void {
    $names = static fn (iterable $commands): array => array_values(array_filter(
        array_map(static fn ($command): string => (string) $command->getName(), [...$commands]),
        static fn (string $name): bool => str_starts_with($name, 'azguard:make:') || in_array($name, ['azguard:install', 'azguard:stubs'], true),
    ));
    $inConsole = $names(app(ConsoleKernel::class)->all());
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
    ConsoleApplication::forgetBootstrappers();

    try {
        $web = test()->createApplication();
        $outside = $names($web->make(ConsoleKernel::class)->all());
        $sections = $web->make(ConfigSections::class);
        $scaffold = static fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['scaffold' => ['folder' => 'x']]]), $sections);
    } finally {
        unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
    }

    // Production keeps validating a host's `scaffold` section: DevTools registers its owner, only its commands are console-only.
    expect($inConsole)->toContain('azguard:install')->and($outside)->toBe([])
        ->and($scaffold)->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard.scaffold: folder');
});

it('row 6 control: with no DevTools to own it the scaffold section is an unknown root', function (): void {
    expect(fn () => AzGuardConfig::fromRepository(new Repository(['azguard' => ['scaffold' => ['folder' => 'x']]]), new ConfigSections))
        ->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard: scaffold');
});
