<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Plugins\PluginContext;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Scopes\Internal\ScopesOptions;
use AzGuard\Scopes\ModelAssignmentScopeDefinition;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ActiveProjects;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\ContextMembership;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ProjectDirectory;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Scopes\RecordingEvaluator;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

/**
 * The admin panel built from its provider description, an optional `configure('admin')` addition of the provider
 * layer and an optional `configureAll` callback of the lowest layer.
 */
function declaredPanel(Closure $provider, ?Closure $configure = null, ?Closure $all = null): Panel
{
    FixturePanel::reset();
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $provider($panel->for(User::class)));
    $registry = new PanelRegistry(app());
    $registry->register(AdminPanel::class);

    if ($configure !== null) {
        $registry->configure('admin', $configure);
    }

    if ($all !== null) {
        $registry->configureAll($all);
    }
    $registry->freeze();

    return $registry->get('admin');
}

/** A third-party plugin that contributes to the scope declaration through the core slot. */
function declaringPlugin(string $id, AssignmentScopeDeclaration $declaration): Plugin
{
    return new class($id, $declaration) implements Plugin
    {
        public function __construct(private readonly string $pluginId, private readonly AssignmentScopeDeclaration $declaration) {}

        public function id(): string
        {
            return $this->pluginId;
        }

        public function register(PanelBuilder $panel, PluginContext $context): void
        {
            $panel->assignmentScopes($this->declaration);
        }
    };
}

it('gives a panel without a scope declaration no declaration, which is mode None', function (): void {
    $panel = declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel);

    expect($panel->scopeDeclaration())->toBeNull()
        ->and($panel->scopeDefinitions())->toBe([])
        ->and($panel->options(ScopesOptions::class))->toBeNull();
});

it('contributes the declaration and the scope options from ScopesPlugin', function (): void {
    Relation::morphMap(['project' => Project::class], false);
    $membership = new ContextMembership(new Membership);
    $panel = declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ScopesPlugin::isolated(Project::class, ConfiguredProjectScope::make()->directory(ProjectDirectory::class), StoreScope::class)
            ->requireMembership($membership),
    ]));
    $declaration = $panel->scopeDeclaration();

    expect($panel->pluginIds())->toContain('azguard/scopes')
        ->and($declaration?->mode)->toBe(AssignmentScopeMode::Isolated)
        ->and(array_map(static fn (object $definition): string => $definition::class, $declaration->definitions ?? []))
        ->toBe([ModelAssignmentScopeDefinition::class, ConfiguredProjectScope::class, StoreScope::class])
        ->and($declaration?->directories)->toBe(['crm.project' => ProjectDirectory::class])
        ->and($declaration?->evaluator)->toBe(ScopeEvaluator::class)
        ->and($panel->options(ScopesOptions::class)?->membership)->toBe($membership)
        ->and($panel->options(ScopesOptions::class)?->adapters)->toBe([]);
});

it('keeps the provider mode when a later layer attaches no scopes plugin', function (): void {
    $inherit = static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ScopesPlugin::inherit(new ProjectScope)]);
    $isolated = static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Isolated, [new StoreScope]));
    $alone = declaredPanel($inherit);
    $layered = declaredPanel($inherit, all: $isolated);
    $provider = declaredPanel($inherit, configure: $isolated);

    expect($alone->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit)
        ->and(array_keys($alone->scopeDefinitions()))->toBe(['crm.project'])
        ->and($layered->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit)
        ->and(array_keys($layered->scopeDefinitions()))->toBe(['store', 'crm.project'])
        ->and($provider->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Isolated)
        ->and(array_keys($provider->scopeDefinitions()))->toBe(['crm.project', 'store']);
});

it('takes the mode of the provider over the scopes plugin and unions the definitions', function (): void {
    $panel = declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ScopesPlugin::inherit(new ProjectScope)])
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::None, [])));

    expect($panel->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::None)
        ->and(array_keys($panel->scopeDefinitions()))->toBe(['crm.project']);
});

it('rejects plugins that set different modes unless the provider sets one', function (): void {
    $plugins = static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
        ScopesPlugin::inherit(new ProjectScope),
        declaringPlugin('acme/stores', new AssignmentScopeDeclaration(AssignmentScopeMode::Isolated, [new StoreScope])),
    ]);

    expect(fn () => declaredPanel($plugins))->toThrow(PluginConflictException::class, 'conflicting assignment scope modes')
        ->and(declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $plugins($panel)
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Required, [])))->scopeDeclaration()?->mode)
        ->toBe(AssignmentScopeMode::Required)
        ->and(declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([
            ScopesPlugin::inherit(new ProjectScope),
            declaringPlugin('acme/stores', new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new StoreScope])),
        ]))->scopeDeclaration()?->mode)->toBe(AssignmentScopeMode::Inherit);
});

it('names one evaluator at most', function (): void {
    $first = new class
    {
        public function __construct() {}
    };
    $declare = static fn (?string $evaluator): AssignmentScopeDeclaration => new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new ProjectScope], evaluator: $evaluator);
    $panel = static fn (?string $a, ?string $b): Panel => declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes($declare($a)),
        static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes($declare($b)));

    expect($panel(RecordingEvaluator::class, null)->scopeDeclaration()?->evaluator)->toBe(RecordingEvaluator::class)
        ->and($panel(RecordingEvaluator::class, RecordingEvaluator::class)->scopeDeclaration()?->evaluator)->toBe(RecordingEvaluator::class)
        ->and(fn () => $panel(RecordingEvaluator::class, ScopeEvaluator::class))->toThrow(DefinitionException::class, 'conflicting assignment scope evaluators')
        ->and(fn () => $panel('Missing\\Evaluator', null))->toThrow(DefinitionException::class, 'does not implement')
        ->and(fn () => $panel($first::class, null))->toThrow(DefinitionException::class, 'does not implement');
});

it('merges directories by scope type and rejects conflicting, unknown and invalid ones', function (): void {
    $other = (new class implements AssignmentScopeDirectory
    {
        public function search(string $type, string $term, LookupContext $lookup, int $limit): array
        {
            return [];
        }

        public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
        {
            return null;
        }
    })::class;
    $declare = static fn (array $directories): AssignmentScopeDeclaration => new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new ProjectScope], $directories);
    $panel = static fn (array $a, array $b): Panel => declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes($declare($a)),
        static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes($declare($b)));

    expect($panel(['crm.project' => ProjectDirectory::class], [])->scopeDeclaration()?->directories)->toBe(['crm.project' => ProjectDirectory::class])
        ->and($panel(['crm.project' => ProjectDirectory::class], ['crm.project' => ProjectDirectory::class])->scopeDeclaration()?->directories)
        ->toBe(['crm.project' => ProjectDirectory::class])
        ->and(fn () => $panel(['crm.project' => ProjectDirectory::class], ['crm.project' => $other]))->toThrow(DefinitionException::class, 'conflicting directories')
        ->and(fn () => $panel(['store' => ProjectDirectory::class], []))->toThrow(DefinitionException::class, 'directory for unknown assignment scope store')
        ->and(fn () => $panel(['crm.project' => StoreScope::class], []))->toThrow(DefinitionException::class, 'assignment scope directories as classes');
});

it('accepts only declaration objects in the scope slot and definition objects inside a declaration', function (): void {
    $recipe = new PanelRecipe('admin');
    $recipe->record(PanelRecipe::SCOPES, 'inherit');
    $classes = new PanelRecipe('admin');
    $classes->record(PanelRecipe::SCOPES, new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ProjectScope::class]));

    expect(fn () => (new PanelCompiler)->compile($recipe, container: app()))->toThrow(DefinitionException::class, 'assignment scope declaration object')
        ->and(fn () => (new PanelCompiler)->compile($classes, container: app()))->toThrow(DefinitionException::class, 'definition objects in its scope declaration');
});

it('creates definition classes without arguments and rejects other strings', function (): void {
    $compile = static fn (ScopesPlugin $plugin) => PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->plugins([$plugin])]);
    $needy = new class('x') extends StoreScope
    {
        public function __construct(public string $needed)
        {
            parent::__construct();
        }
    };

    expect(fn () => $compile(ScopesPlugin::inherit($needy::class)))->toThrow(DefinitionException::class, 'without arguments: pass a definition object')
        ->and(fn () => $compile(ScopesPlugin::inherit(PanelRecipe::class)))->toThrow(DefinitionException::class, 'definition objects or classes, or Eloquent model classes')
        ->and(fn () => $compile(ScopesPlugin::required()))->toThrow(DefinitionException::class, 'registers no definitions');
});

it('keeps the directory of the merged definition and the declared map one class per scope type', function (): void {
    $other = (new class implements AssignmentScopeDirectory
    {
        public function search(string $type, string $term, LookupContext $lookup, int $limit): array
        {
            return [];
        }

        public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
        {
            return null;
        }
    })::class;
    $plugin = static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([ScopesPlugin::inherit(ConfiguredProjectScope::make()->directory(ProjectDirectory::class))]);
    $later = declaredPanel($plugin, static fn (PanelBuilder $panel): PanelBuilder => $panel
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ConfiguredProjectScope::make()->directory($other)], evaluator: ScopeEvaluator::class)));

    expect($later->scopeDeclaration()?->directories)->toBe(['crm.project' => $other])
        ->and($later->scopeDefinition('crm.project')?->settings()->directory)->toBe($other)
        ->and(fn () => declaredPanel($plugin, static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [], ['crm.project' => $other]))))
        ->toThrow(DefinitionException::class, 'conflicting directories');
});

it('lets the provider settle the mode when ScopesPlugin and another plugin disagree', function (): void {
    $plugins = [ScopesPlugin::inherit(new ProjectScope), declaringPlugin('acme/stores', new AssignmentScopeDeclaration(AssignmentScopeMode::Isolated, [new StoreScope]))];

    expect(fn () => declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins($plugins)))
        ->toThrow(PluginConflictException::class, 'set the mode in the panel provider')
        ->and(declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins($plugins)
            ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [])))->scopeDeclaration()?->mode)
        ->toBe(AssignmentScopeMode::Inherit);
});

it('rejects filters that no evaluator would apply', function (): void {
    $filtered = new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [ConfiguredProjectScope::make()->filter(new ActiveProjects)]);

    expect(fn () => declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes($filtered)))
        ->toThrow(DefinitionException::class, 'without an evaluator that applies them')
        ->and(declaredPanel(static fn (PanelBuilder $panel): PanelBuilder => $panel->assignmentScopes(
            new AssignmentScopeDeclaration(AssignmentScopeMode::None, $filtered->definitions)))->scopeDeclaration()?->mode)
        ->toBe(AssignmentScopeMode::None);
});
