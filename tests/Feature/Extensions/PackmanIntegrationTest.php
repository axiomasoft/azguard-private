<?php

declare(strict_types=1);

use AzGuard\AzGuard;
use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Panels\PanelRegistry as AzGuardPanelRegistry;
use AzGuard\Diagnostics\AzGuardHealthCheck;
use AzGuard\Diagnostics\Doctor;
use AzGuard\Errors\AzGuardErrorCode;
use AzGuard\Errors\AzGuardProblemDefinitions;
use AzGuard\Exceptions\Authorization\RecursionDetectedException;
use AzGuard\Exceptions\Authorization\VisibilityNotSupportedException;
use AzGuard\Extensions\AzGuardCorePlugin;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\AzGuardPanelProvider;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Schema\AzGuardSchemaSets;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePolicy;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use AzGuard\Tests\Fixtures\Sources\Database\EditorRole;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\Relation;
use Packman\Attributes\Describe;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Context\Data\ScopeRef;
use Packman\Doctor\Data\DoctorContext as PackmanDoctorContext;
use Packman\Doctor\Enums\Severity;
use Packman\Doctor\Laravel\DoctorChecks;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;
use Packman\Extensions\ExtensionInspector;
use Packman\PackmanServiceProvider;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Laravel\SchemaServiceProvider;

it('inspects AzGuardCorePlugin as a standalone extension without database registration', function (): void {
    $plugin = new AzGuardCorePlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect($plugin)->not->toBeInstanceOf(WithDatabaseRegistration::class)
        ->and($plugin->manifest()->id)->toBe('axiomasoft.azguard')
        ->and($profile->hasConfiguration)->toBeTrue()
        ->and($profile->configRoot)->toBe('azguard')
        ->and($profile->optionsClass)->toBe(AzGuardOptions::class)
        ->and($profile->hasSchema)->toBeTrue()
        ->and($profile->tablePrefix)->toBe('azg_')
        ->and($profile->tableNouns)->toContain('permissions', 'role_grants', 'permission_grants', 'panel_state', 'subject_revisions', 'storage_state')
        ->and($profile->schemaSetProviders)->toBe([AzGuardSchemaSets::class])
        ->and($profile->healthChecks)->toBe([AzGuardHealthCheck::class])
        ->and($profile->problemProviders)->toBe([AzGuardProblemDefinitions::class])
        ->and($profile->panelProviders)->toBe([AzGuardPanelProvider::class])
        ->and($profile->definedCapabilities)->toHaveKeys([AzGuard::class, AzGuardPanelRegistry::class, Doctor::class])
        ->and($profile->definedSlots)->toHaveKey(BeforeHook::class);
});

it('validates AzGuardOptions closed schema, explicit nulls, and SecretRef redaction', function (): void {
    $options = AzGuardOptions::fromArray([
        'defaults' => ['cache' => ['ttl' => null]],
        'cache' => ['signing_key' => 'super-secret-hmac-key'],
    ]);

    $signingKey = $options->cacheSigningKey();

    expect($signingKey)->toBeInstanceOf(SecretRef::class)
        ->and($signingKey->isPresent())->toBeTrue()
        ->and($signingKey->reveal())->toBe('super-secret-hmac-key')
        ->and((string) $signingKey)->toBe('[redacted]')
        ->and($signingKey->__debugInfo())->toBe(['value' => '[redacted]']);

    expect(fn (): AzGuardOptions => AzGuardOptions::fromArray(['caches' => []]))
        ->toThrow(InvalidConfiguration::class);
});

it('registers error codes, problem definitions, schema sets, health check, and panel provider through Packman', function (): void {
    app()->register(PackmanServiceProvider::class);
    app()->register(SchemaServiceProvider::class);

    $problemProviders = app()->tagged(ProblemDefinitionProvider::class);
    $foundAzGuardProblems = false;
    foreach ($problemProviders as $contribution) {
        if ($contribution->owner === 'azguard') {
            $foundAzGuardProblems = true;
        }
    }

    $registry = app(ProblemRegistry::class);
    $schemaSets = iterator_to_array(app()->tagged(SchemaSetProvider::class), false);
    $doctorChecks = iterator_to_array(app()->tagged(DoctorChecks::TAG), false);
    $panelProviders = iterator_to_array(app()->tagged(app(PanelsOptions::class)->providerTag), false);
    $ownership = app(SchemaOwnership::class);

    $azgSet = null;
    foreach ($schemaSets as $provider) {
        if ($provider instanceof AzGuardSchemaSets) {
            $azgSet = $provider->sets()[0] ?? null;
        }
    }

    $recursionError = new RecursionDetectedException('cycle');
    $visibilityError = new VisibilityNotSupportedException('source_error', 'custom');

    expect($foundAzGuardProblems)->toBeTrue()
        ->and($registry->get(AzGuardErrorCode::UnknownPanel)->defaultStatus)->toBe(404)
        ->and($registry->get(AzGuardErrorCode::UnknownPanel)->type)->toBe('urn:packman:problem:azguard.unknown_panel')
        ->and($recursionError->errorDescriptor()->code)->toBe(AzGuardErrorCode::RecursionDetected->value)
        ->and($visibilityError->errorDescriptor()->code)->toBe(AzGuardErrorCode::VisibilityNotSupported->value)
        ->and($azgSet)->not->toBeNull()
        ->and($azgSet->scope)->toBe(SchemaScope::Central)
        ->and($azgSet->lifecycle)->toBe(SchemaLifecycle::Platform)
        ->and($azgSet->drivers)->toBe(['pgsql', 'mysql', 'mariadb', 'sqlite'])
        ->and(is_dir($azgSet->path))->toBeTrue()
        ->and($ownership->ownerOf('role_grants'))->toBe('axiomasoft.azguard')
        ->and(array_map(static fn (object $c): string => $c::class, $doctorChecks))->toContain(AzGuardHealthCheck::class)
        ->and(array_map(static fn (object $p): string => $p::class, $panelProviders))->toContain(AzGuardPanelProvider::class);
});

it('evaluates AzGuardHealthCheck and AzGuardPanelProvider access against compiled panel state', function (): void {
    Relation::morphMap(['user' => User::class], false);
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();

    $source = DatabaseSource::make();
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)
            ->default()
            ->entry(DatabasePermission::View)
            ->permissions([DatabasePermission::class, $source])
            ->roles([EditorRole::class])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)]),
    ]);
    app()->instance(PanelRegistry::class, $registry);

    DatabaseWorld::insert('role', [DatabaseWorld::row('role', overrides: ['subject_id' => '1'])]);

    $provider = new AzGuardPanelProvider(app());
    $ref = new PanelReference('azguard', 'admin', null);
    $guest = new PanelContext(new ScopeRef('global'), 'guest', null);
    $denied = new PanelContext(new ScopeRef('global'), 'user', '999');
    $allowed = new PanelContext(new ScopeRef('global'), 'user', '1');
    $unknownPanel = new PanelReference('azguard', 'missing', null);

    $guestAccess = $provider->access($guest, $ref);
    $deniedAccess = $provider->access($denied, $ref);
    $missingAccess = $provider->access($allowed, $unknownPanel);
    $grantedAccess = $provider->access($allowed, $ref);
    $findings = app(AzGuardHealthCheck::class)->run(new PackmanDoctorContext(production: false));

    expect($provider->descriptor()->key)->toBe('azguard')
        ->and($guestAccess->view)->toBeFalse()
        ->and($guestAccess->actions)->toBe([])
        ->and($guestAccess->reason)->toBe('unauthenticated_actor')
        ->and($deniedAccess->view)->toBeFalse()
        ->and($deniedAccess->actions)->toBe([])
        ->and($deniedAccess->reason)->toBe('panel_access_denied')
        ->and($missingAccess->view)->toBeFalse()
        ->and($missingAccess->actions)->toBe([])
        ->and($missingAccess->reason)->toBe('unknown_panel')
        ->and($grantedAccess->view)->toBeTrue()
        ->and($grantedAccess->actions)->toBe(['view'])
        ->and($findings)->not->toBeEmpty()
        ->and(array_filter($findings, static fn ($f): bool => $f->severity === Severity::Error))->toBe([]);
});

it('fails closed when container or bindings are missing and filters per-action permissions', function (): void {
    Relation::morphMap(['user' => User::class], false);
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();

    $ref = new PanelReference('azguard', 'admin', null);
    $allowed = new PanelContext(new ScopeRef('global'), 'user', '1');

    $nullContainerAccess = (new AzGuardPanelProvider(null))->access($allowed, $ref);
    $emptyContainerAccess = (new AzGuardPanelProvider(new Container))->access($allowed, $ref);

    expect($nullContainerAccess->view)->toBeFalse()
        ->and($nullContainerAccess->actions)->toBe([])
        ->and($nullContainerAccess->reason)->toBe('panel_access_unavailable')
        ->and($emptyContainerAccess->view)->toBeFalse()
        ->and($emptyContainerAccess->actions)->toBe([])
        ->and($emptyContainerAccess->reason)->toBe('panel_access_unavailable');

    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)
            ->default()
            ->permissions([DatabasePermission::class, HeadlessPanelPermission::class, DatabaseSource::make()])
            ->roles([EditorRole::class])
            ->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)]),
    ]);
    app()->instance(PanelRegistry::class, $registry);

    $source = app(AzGuard::class)->panel('admin');
    $subject = SubjectRef::of('user', '1');
    $source->for($subject)->grantRole('editor');
    $provider = new AzGuardPanelProvider(app());

    // Without azguard.view permission, panel access fails closed even when holding a role.
    $withoutView = $provider->access($allowed, $ref);
    expect($withoutView->view)->toBeFalse()
        ->and($withoutView->actions)->toBe([])
        ->and($withoutView->reason)->toBe('view_permission_denied');

    // Explicit tenant on non-tenant panel fails closed.
    $tenantAccess = $provider->access(new PanelContext(new ScopeRef('acme'), 'user', '1'), $ref);
    expect($tenantAccess->view)->toBeFalse()
        ->and($tenantAccess->actions)->toBe([])
        ->and($tenantAccess->reason)->toBe('invalid_tenant');

    // Granting a subset of actions returns only those authorized actions.
    $source->for($subject)->grantPermission('azguard.view');
    $source->for($subject)->grantPermission('azguard.grant');
    $source->for($subject)->grantPermission('azguard.diagnose');
    $partial = $provider->access($allowed, $ref);

    expect($partial->view)->toBeTrue()
        ->and($partial->actions)->toBe(['view', 'grant', 'diagnose']);

    $source->for($subject)->grantPermission('azguard.revoke');
    $source->for($subject)->grantPermission('azguard.manage-permissions');
    $full = $provider->access($allowed, $ref);

    expect($full->view)->toBeTrue()
        ->and($full->actions)->toBe(['view', 'grant', 'revoke', 'manage-permissions', 'diagnose']);
});

it('registers built-in Packman ExtensionPlugins at provider boot and compiles AzGuardCorePlugin cleanly', function (): void {
    $compiled = PluginGraph::compile(new Container, [AzGuardCorePlugin::class]);
    $ids = array_map(static fn ($d): string => $d->id, app(PluginInspector::class)->describe());

    expect($compiled->describe())->toHaveCount(1)
        ->and($compiled->describe()[0]->id)->toBe('axiomasoft.azguard')
        ->and((new AzGuardCorePlugin)->providedCapabilities())->toHaveKey(AzGuardPanelRegistry::class, PanelRegistry::class)
        ->and($ids)->toContain('axiomasoft.azguard', 'axiomasoft.azguard-scopes', 'axiomasoft.azguard-audit', 'axiomasoft.azguard-devtools', 'axiomasoft.azguard-filament');
});

#[RequiresGrant]
enum HeadlessPanelPermission: string
{
    #[Describe('View headless panel')]
    case View = 'azguard.view';

    #[Describe('Grant access')]
    case Grant = 'azguard.grant';

    #[Describe('Revoke access')]
    case Revoke = 'azguard.revoke';

    #[Describe('Manage permissions')]
    case ManagePermissions = 'azguard.manage-permissions';

    #[Describe('Diagnose panel')]
    case Diagnose = 'azguard.diagnose';
}
