<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelCompiler;
use AzGuard\Panels\Internal\PanelFingerprint;
use AzGuard\Panels\Internal\PanelRecipe;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Internal\Checks\ScopeMembershipConfigured;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Scopes\Internal\ScopesOptions;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Modules\Scopes\Fixtures\ActiveProjects;
use AzGuard\Tests\Modules\Scopes\Fixtures\ConfigCountingFilter;
use AzGuard\Tests\Modules\Scopes\Fixtures\ConfigGenericScope;
use AzGuard\Tests\Modules\Scopes\Fixtures\ConfiguredProjectScope;
use AzGuard\Tests\Modules\Scopes\Fixtures\ContextMembership;
use AzGuard\Tests\Modules\Scopes\Fixtures\EligibilityExternalAdapter;
use AzGuard\Tests\Modules\Scopes\Fixtures\Project;
use AzGuard\Tests\Modules\Scopes\Fixtures\ProjectDirectory;
use AzGuard\Tests\Modules\Scopes\Fixtures\StoreScope;
use Illuminate\Database\Eloquent\Builder;

it('registers typed access adapters on an immutable plugin', function (): void {
    $adapter = new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return true;
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return [];
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $contextQuery;
        }
    };
    $policy = ScopesPlugin::inherit(StoreScope::class);
    $configured = $policy->accessAdapter('store', $adapter);
    [, , $registry] = PanelWorld::compile([
        AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([$configured]),
        CabinetPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([$policy]),
    ]);

    expect($registry->get('cabinet')->options(ScopesOptions::class)?->adapters)->toBe([])
        ->and($registry->get('admin')->options(ScopesOptions::class)?->adapters)->toBe(['store' => $adapter]);
});

it('rejects invalid access adapter type aliases and removed classes', function (): void {
    expect(fn () => ScopesPlugin::inherit()->accessAdapter('invalid:type', AssignmentScopeAccessAdapter::class))
        ->toThrow(DefinitionException::class, 'type alias');
    expect(fn () => ScopesPlugin::inherit()->accessAdapter('store', 'RemovedAdapter'))
        ->toThrow(DefinitionException::class, 'implement');
});

it('reports an unbound access adapter interface through the doctor and rejects adapters of unknown scope types', function (): void {
    $compile = fn (ScopesPlugin $policy) => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([$policy])]);
    [, , $registry] = $compile(ScopesPlugin::inherit(StoreScope::class)->accessAdapter('store', AssignmentScopeAccessAdapter::class));
    $findings = ScopeMembershipConfigured::check($registry->get('admin'), app());
    expect(array_map(static fn ($finding): string => $finding->key.' '.($finding->isError() ? 'error' : 'warning'), $findings))->toContain('membership.configured error')
        ->and(implode(' ', array_map(static fn ($finding): string => $finding->message, $findings)))->toContain('access adapter of assignment scope store')
        ->and(array_filter(ScopeMembershipConfigured::check(PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)
            ->plugins([ScopesPlugin::inherit(StoreScope::class)->accessAdapter('store', EligibilityExternalAdapter::class)])])[2]->get('admin'), app()),
            static fn ($finding): bool => $finding->isError()))
        ->toBe([]);
    expect(fn () => $compile(ScopesPlugin::inherit(StoreScope::class)->accessAdapter('other', new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return true;
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return [];
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $contextQuery;
        }
    })))
        ->toThrow(DefinitionException::class, 'unknown assignment scope');
});

it('accepts class object and closure filters without resolving typed classes at build', function (): void {
    ConfigCountingFilter::$instances = 0;
    $definition = ConfiguredProjectScope::make()->filter(ConfigCountingFilter::class)->filter(new ActiveProjects)
        ->filter(static function (Builder $query, AssignmentScopeRuntime $runtime): void {
            $query->where('is_active', true);
        });
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]);
    expect(ConfigCountingFilter::$instances)->toBe(0)
        ->and($registry->get('admin')->scopeDefinition('crm.project')->settings()->filters)->toHaveCount(3);
});

it('rejects an unbound filter interface at build and accepts its bound deployment', function (): void {
    $definition = ConfiguredProjectScope::make()->filter(AssignmentScopeFilter::class);
    $compile = fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]);
    expect($compile)->toThrow(DefinitionException::class, 'container binding');
    app()->bind(AssignmentScopeFilter::class, ConfigCountingFilter::class);
    ConfigCountingFilter::$instances = 0;
    expect($compile)->not->toThrow(DefinitionException::class)
        ->and(ConfigCountingFilter::$instances)->toBe(0);
});

it('rejects native filters for generic definitions and reports removed filter classes', function (): void {
    $generic = (new ConfigGenericScope)->filter(new ActiveProjects);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($generic)])]))
        ->toThrow(DefinitionException::class, 'queryable model');
    expect(fn () => ConfiguredProjectScope::make()->filter('RemovedFilter'))->toThrow(DefinitionException::class, 'filter()');
});

it('rejects filter constructor dependencies that cannot be resolved without a binding', function (): void {
    $filter = new class('active') implements AssignmentScopeFilter
    {
        public function __construct(public string $column) {}

        public function apply(Builder $query, AssignmentScopeRuntime $runtime): void {}
    };
    $definition = ConfiguredProjectScope::make()->filter($filter::class);
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]))
        ->toThrow(DefinitionException::class, 'constructor parameter');
});

it('keeps identical contributions of one alias once and rejects different filters, label or directory', function (ConfiguredProjectScope $later): void {
    $compile = function (ConfiguredProjectScope ...$definitions): Panel {
        $recipe = new PanelRecipe('admin');
        foreach ([PanelRecipe::configure(), PanelRecipe::provider()] as $i => $origin) {
            $recipe->during($origin, fn () => $recipe->record(PanelRecipe::SCOPES, new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [$definitions[$i]], evaluator: ScopeEvaluator::class)));
        }

        return (new PanelCompiler)->compile($recipe, container: app());
    };
    $first = ConfiguredProjectScope::make()->label('Projects')->filter(new ActiveProjects);

    expect($compile($first, ConfiguredProjectScope::make()->label('Projects')->filter(new ActiveProjects))->scopeDefinition('crm.project'))->toBe($first)
        ->and(fn () => $compile($first, $later))->toThrow(DefinitionException::class, 'declares conflicting assignment scope definitions for crm.project');
})->with([
    'another filter' => fn () => ConfiguredProjectScope::make()->label('Projects')->filter(new ActiveProjects)->filter(ConfigCountingFilter::class),
    'no filter' => fn () => ConfiguredProjectScope::make()->label('Projects'),
    'another label' => fn () => ConfiguredProjectScope::make()->label('Other')->filter(new ActiveProjects),
    'a directory' => fn () => ConfiguredProjectScope::make()->label('Projects')->filter(new ActiveProjects)->directory(ProjectDirectory::class),
]);

it('changes the fingerprint when a filter, the membership or an access adapter changes', function (): void {
    $fingerprint = function (ScopesPlugin $plugin): string {
        [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([$plugin])]);

        return $registry->fingerprint('admin');
    };
    $base = $fingerprint(ScopesPlugin::inherit(StoreScope::class, ConfiguredProjectScope::make()));

    expect($fingerprint(ScopesPlugin::inherit(StoreScope::class, ConfiguredProjectScope::make())))->toBe($base)
        ->and($fingerprint(ScopesPlugin::inherit(StoreScope::class, ConfiguredProjectScope::make()->filter(new ActiveProjects))))->not->toBe($base)
        ->and($fingerprint(ScopesPlugin::inherit(StoreScope::class, ConfiguredProjectScope::make())->requireMembership(ContextMembership::class)))->not->toBe($base)
        ->and($fingerprint(ScopesPlugin::inherit(StoreScope::class, ConfiguredProjectScope::make())->accessAdapter('store', EligibilityExternalAdapter::class)))->not->toBe($base);
});

it('rejects a changed owner despite identical model class and scope type', function (): void {
    $recipe = new PanelRecipe('admin');
    $recipe->record(PanelRecipe::SCOPES, new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ModelAssignmentScopeDefinition::make(Project::class, type: 'project')], evaluator: ScopeEvaluator::class));
    $recipe->record(PanelRecipe::SCOPES, new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ModelAssignmentScopeDefinition::make(Project::class, owner: static fn () => TenantRef::global(), type: 'project')], evaluator: ScopeEvaluator::class));
    expect(fn () => (new PanelCompiler)->compile($recipe, container: app()))->toThrow(DefinitionException::class, 'conflicting');
});

it('fingerprints scalar closure configuration and rejects live runtime captures', function (): void {
    $fingerprint = function (int $city): string {
        $recipe = new PanelRecipe('admin');
        $recipe->record(PanelRecipe::SCOPES, new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ConfiguredProjectScope::make()
            ->filter(static function (Builder $query) use ($city): void {
                $query->where('city_id', $city);
            })], evaluator: ScopeEvaluator::class));
        $panel = (new PanelCompiler)->compile($recipe, container: app());

        return PanelFingerprint::of($panel, $recipe);
    };
    expect($fingerprint(1))->toBe($fingerprint(1))->not->toBe($fingerprint(2));
    $project = new Project;
    $definition = ConfiguredProjectScope::make()->filter(static function (Builder $query) use ($project): void {
        $query->whereKey($project->getKey());
    });
    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]))
        ->toThrow(DefinitionException::class, 'live model');
});

it('rejects a filter closure that cannot be called as ($query, $runtime) when the panel compiles', function (Closure $filter): void {
    $definition = ConfiguredProjectScope::make()->filter($filter);

    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]))
        ->toThrow(DefinitionException::class, 'Assignment scope crm.project has a filter closure that cannot be called as ($query, $runtime)');
})->with([
    'a name-injected user' => [static fn (Builder $query, User $user) => null],
    'a name-injected actor' => [static fn (Builder $query, ?ActorRef $actor) => null],
    'a third required parameter' => [static fn (Builder $query, AssignmentScopeRuntime $runtime, array $proposed) => null],
    'a query that is not a builder' => [static fn (string $query) => null],
]);

it('accepts a filter closure called as ($query, $runtime), untyped or with fewer parameters', function (Closure $filter): void {
    $definition = ConfiguredProjectScope::make()->filter($filter);

    expect(fn () => PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)->plugins([ScopesPlugin::inherit($definition)])]))
        ->not->toThrow(DefinitionException::class);
})->with([
    'typed' => [static fn (Builder $query, AssignmentScopeRuntime $runtime) => null],
    'untyped' => [static fn ($query, $runtime) => null],
    'query only' => [static fn (Builder $query) => null],
    'no parameters' => [static fn () => null],
    'optional extra' => [static fn (Builder $query, ?AssignmentScopeRuntime $runtime = null, array $extra = []) => null],
]);
