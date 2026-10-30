<?php

declare(strict_types=1);

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Diagnostics\Internal\Checks\PanelsSources;
use AzGuard\Diagnostics\PanelOverview;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Sources\Internal\PanelSources;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

class CapabilityMinimalSource implements Source
{
    public function id(): string
    {
        return 'minimal';
    }
}

class CapabilityStableUnfencedSource extends GeneratedSource
{
    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }
}

class CapabilityStableFencedSource extends VisibilitySource implements FencesReads
{
    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        return StateToken::of('test', $panel->id(), 'fixed', 1, 1, 'fingerprint');
    }
}

#[Role('capability-automatic')]
class CapabilityAutomaticRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        throw new LogicException('Doctor must not evaluate an automatic role.');
    }
}

/** @return list<DoctorFinding> */
function capabilityFindings(Panel $panel): array
{
    $context = new DoctorContext([$panel], [], false, app(AzGuardConfig::class));

    return iterator_to_array((new PanelsSources(app(PanelRegistry::class)))->run($context));
}

/** The published matrix uses actual marker declarations, without reading grants or evaluating policies. */
function capabilityMatrixDocument(): array
{
    $sources = [
        'folder' => FolderSource::make(),
        'database' => DatabaseSource::make(),
        'relation' => RelationSource::make(new VisibilityProjectScope, 'members', 'viewer'),
        'gate' => GateSource::make(),
        'filament' => FilamentSource::make('admin'),
        'custom_minimal' => new CapabilityMinimalSource,
        'custom_volatile' => new VisibilitySource,
        'custom_stable_unfenced' => new CapabilityStableUnfencedSource,
        'custom_stable_fenced' => new CapabilityStableFencedSource,
    ];
    $rows = [];
    foreach ($sources as $profile => $source) {
        $description = new SourceDescription($profile, $source::class, PanelSources::capabilities($source), false,
            volatility: $source instanceof ProvidesGrants || $source instanceof ProvidesRoleGrants ? $source->volatility() : null);
        $rows[$profile] = [
            'class' => $description->class,
            'capabilities' => $description->capabilities,
            'volatility' => $description->volatility?->value,
            'matrix' => $description->matrix,
        ];
    }
    $rows['folder_automatic_role'] = $rows['folder'];
    $rows['folder_automatic_role']['matrix']['exact_visibility'] = [
        'status' => 'unsupported', 'basis' => 'static_declaration',
        'reason' => 'A registered GrantedAutomatically role makes FolderSource::contextsCovering return null, even if the role has a predicate adapter.',
        'test_reference' => 'tests/Feature/Diagnostics/SourceCapabilitiesTest.php',
    ];

    return [
        'schema' => 'urn:azguard:source-capabilities:2',
        'basis' => 'static_declaration',
        'runtime_unsupported' => [
            'exception' => VisibilityNotSupportedException::class,
            'semantics' => 'Conditional declarations never prove arbitrary closures or policies. Unsupported selections or predicates throw before the host query runs; never Allow or filter a paginated result in PHP.',
            'test_reference' => 'tests/Feature/Visibility/UnsupportedTest.php',
        ],
        'sources' => $rows,
    ];
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('preserves the minimal legacy SPI and old SourceDescription constructor', function (): void {
    expect(array_map(static fn (ReflectionMethod $method): string => $method->name, (new ReflectionClass(Source::class))->getMethods()))->toBe(['id']);
    $legacy = new SourceDescription('minimal', CapabilityMinimalSource::class, [], false);
    expect($legacy->volatility)->toBeNull()->and($legacy->matrix['scalar']['status'])->toBe('supported');
    [, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant()]), extra: [new CapabilityMinimalSource]);
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'));
    expect($authorizer->decide($panel, $request)->allowed())->toBeTrue()
        ->and($authorizer->decideMany([$request])->get(0)->allowed())->toBeTrue()
        ->and(capabilityFindings($panel))->toBe([]);
});

it('publishes built-in and custom capability profiles with reasons and executable references', function (): void {
    $document = capabilityMatrixDocument();
    foreach ($document['sources'] as $row) {
        expect(array_keys($row['matrix']))->toBe(['scalar', 'batch', 'exact_visibility', 'stable_cache', 'tenant_inheritance', 'filament']);
        foreach ($row['matrix'] as $cell) {
            expect($cell['status'])->toBeIn(['supported', 'unsupported', 'conditional'])
                ->and($cell['basis'])->toBe('static_declaration')->and($cell['reason'])->not->toBe('')
                ->and(is_file(dirname(__DIR__, 3).'/'.$cell['test_reference']))->toBeTrue();
        }
    }
    [$actual, $stored] = FormatSnapshot::compare('source-capabilities', $document);
    $published = dirname(__DIR__, 3).'/docs/reference/source-capabilities.json';

    if (FormatSnapshot::writing()) {
        file_put_contents($published, $actual);
    }
    expect($actual)->toBe($stored)->and(file_get_contents($published))->toBe($actual)
        ->and($document['sources']['custom_volatile']['matrix']['stable_cache']['status'])->toBe('unsupported')
        ->and($document['sources']['custom_stable_unfenced']['matrix']['stable_cache']['status'])->toBe('unsupported')
        ->and($document['sources']['database']['matrix']['stable_cache']['status'])->toBe('conditional');
});

it('reports stable unfenced and missing query declarations before the first grant read', function (): void {
    $source = new CapabilityStableUnfencedSource(name: 'legacy');
    [$visibility, $panel] = W::compile(extra: [$source]);
    $findings = capabilityFindings($panel);
    expect(array_column($findings, 'key'))->toBe(['panels.sources.visibility', 'panels.sources.cache'])
        ->and(array_column(array_column($findings, 'details'), 'reason'))->toBe(['missing_query_support', 'stable_unfenced'])
        ->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
    foreach ($findings as $finding) {
        expect($finding->isError())->toBeFalse()->and($finding->scope)->toBe('panel:admin');
    }
    $description = array_values(array_filter($panel->sources(), static fn (SourceDescription $s): bool => $s->id === 'legacy'))[0];
    expect($description->volatility)->toBe(Volatility::Stable)->and($description->matrix['exact_visibility']['status'])->toBe('unsupported');
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'source_error');
});

it('exports the normalized description through the machine panel overview', function (): void {
    [, $panel] = W::compile(new CapabilityStableFencedSource);
    $sources = app(PanelOverview::class)->panel($panel, sources: true)['sources']['sources'];
    $source = array_values(array_filter($sources, static fn (array $s): bool => $s['id'] === 'generated'))[0];
    expect($source['volatility'])->toBe('stable')->and($source['matrix']['stable_cache']['status'])->toBe('conditional')
        ->and(capabilityFindings($panel))->toBe([]);
});

it('warns about declared automatic folder roles without evaluating them', function (): void {
    [$visibility, $panel] = W::compile(configure: static fn (PanelBuilder $builder) => $builder->roles([CapabilityAutomaticRole::class]));
    $findings = capabilityFindings($panel);
    expect($findings)->toHaveCount(1)->and($findings[0]->key)->toBe('panels.sources.visibility')
        ->and($findings[0]->details['reason'])->toBe('automatic_roles')
        ->and($findings[0]->details['roles'])->toBe([CapabilityAutomaticRole::class]);
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'unsupported_selection');
});

it('keeps a conditional declaration separate from a runtime unsupported selection', function (): void {
    $source = new VisibilitySource(direct: [W::grant(1)]);
    $source->selection = static fn () => null;
    [$visibility, $panel] = W::compile($source);
    expect(capabilityFindings($panel))->toBe([]);
    $sourceDescription = array_values(array_filter($panel->sources(), static fn (SourceDescription $s): bool => $s->id === 'generated'))[0];
    expect($sourceDescription->matrix['exact_visibility']['status'])->toBe('conditional');
    $query = VisibilityProject::query()->where('active', true);
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'unsupported_selection');
    expect($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings);
});
