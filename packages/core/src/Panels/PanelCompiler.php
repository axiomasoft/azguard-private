<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\AssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Tenancy\TenantResolver;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\PluginConflictException;
use AzGuard\Exceptions\PrefixConflictException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Plugins\PluginContext;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Scopes\Internal\ScopeConfiguration;
use AzGuard\Sources\PanelSources;
use AzGuard\Tenancy\TenantPolicy;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use UnitEnum;

/**
 * Registers the plugins of a panel, turns its sealed recipe into a panel and checks what only the whole set of
 * panels can tell.
 *
 * A scalar setting is taken from the highest layer that sets it: the panel provider (with its `configure(id)`
 * additions), then plugins, then `configure` for all panels, then the `defaults` of the configuration. Inside a
 * layer the last record wins. Two plugins that set one setting to different values are a conflict unless the
 * provider decides. A list keeps the items of every layer; no layer replaces another one.
 *
 * @phpstan-import-type Record from PanelRecipe
 *
 * @phpstan-type Resolved array{value: mixed, origin: string}
 */
final class PanelCompiler
{
    private const string PLUGIN_ID = '/\A[a-z0-9][a-z0-9_.-]*(\/[a-z0-9][a-z0-9_.-]*)?\z/';

    private const int PLUGIN_ID_BYTES = 128;

    /** Base names of the authority tables only the core writer touches; no participant may declare one. */
    private const array CORE_TABLES = ['permissions', 'role_grants', 'permission_grants', 'panel_state', 'subject_revisions', 'storage_state'];

    /**
     * @param  (Closure(): array<string, bool|int|string|null>)|null  $defaults  values of the `defaults` section
     *                                                                           of the configuration by setting
     *                                                                           name, read when a panel is compiled
     * @param  (Closure(): array{tenants: list<class-string>, scopes: list<class-string>})|null  $resolvers  tenant and
     *                                                                                                       assignment scope resolvers of the
     *                                                                                                       configuration, tried after those of
     *                                                                                                       the panel
     */
    public function __construct(private readonly ?Closure $defaults = null, private readonly ?Closure $resolvers = null) {}

    /**
     * Runs `register()` of every plugin of the panel and seals the recipe.
     *
     * Plugins of the panel provider register first, then the ones `configure` for all panels attaches unless the
     * panel keeps them off, then the ones a plugin attached while it registered, until no new plugin appears. A
     * plugin registers on its own copy, so what it keeps while registering on one panel is not seen on another.
     *
     * @throws DefinitionException when a plugin cannot be created or its id is malformed
     * @throws PluginConflictException when two plugins of the panel share an id, or a plugin outside the package
     *                                 takes an id of the reserved `azguard/` prefix
     */
    public function register(PanelRecipe $recipe, PanelBuilder $builder, Container $container, string $buildId): void
    {
        $panel = $recipe->panelId();
        $detached = $recipe->withoutPlugins();
        $queue = [
            ...array_map(static fn (Plugin|string $plugin): array => [$plugin, false], $recipe->plugins(PanelRecipe::PROVIDER)),
            ...array_map(static fn (Plugin|string $plugin): array => [$plugin, true], $recipe->plugins(PanelRecipe::CONFIGURE)),
        ];
        $attached = [];
        $nested = 0;

        while ($queue !== []) {
            foreach ($queue as [$declared, $detachable]) {
                $plugin = $this->instance($panel, $declared, $container);
                $id = $this->pluginId($panel, $plugin);

                if ($detachable && in_array($id, $detached, true)) {
                    continue;
                }

                if (isset($attached[$id])) {
                    throw new PluginConflictException(
                        'Panel "'.$panel.'" has two plugins with the id "'.$id.'" ('.$attached[$id]::class.' and '
                        .$plugin::class.'): attach the plugin once.',
                    );
                }

                $this->assertIdOwned($panel, $id, $plugin);
                $context = new PluginContext($panel, $id, $buildId);
                $attached[$id] = $plugin;

                $recipe->during($recipe->attach($id), static fn () => $plugin->register($builder, $context));
            }

            $byPlugins = $recipe->plugins(PanelRecipe::PLUGIN);
            $queue = array_map(static fn (Plugin|string $plugin): array => [$plugin, false], array_slice($byPlugins, $nested));
            $nested = count($byPlugins);
        }

        $recipe->seal();
    }

    /**
     * @throws PluginConflictException
     * @throws InvalidConfigurationException
     */
    public function compile(PanelRecipe $recipe, ?PanelSources $sources = null, ?Container $container = null): Panel
    {
        $id = $recipe->panelId();
        $label = $this->resolved($recipe, PanelRecipe::LABEL)['value'] ?? null;
        $tenants = $this->resolved($recipe, PanelRecipe::TENANTS)['value'] ?? TenantPolicy::none();

        if (! $tenants instanceof TenantPolicy) {
            throw new DefinitionException('Panel '.$id.' requires a tenant policy object.');
        }
        $this->membership($tenants->membership(), $container, 'tenant_scope', 'Tenant');
        $options = $this->options($recipe);
        [$scopes, $definitions] = $this->scopeDeclaration($recipe, $tenants, $container);
        ScopeConfiguration::checkOptions($id, $options, array_keys($definitions), $container);

        $subjects = $this->subjects($recipe);

        return new Panel(
            id: $id,
            label: is_string($label) ? $label : $id,
            default: ($this->resolved($recipe, PanelRecipe::DEFAULT)['value'] ?? false) === true,
            settings: $this->settings($recipe),
            subjectModels: array_map(static fn (SubjectDescriptor $subject): string => $subject->model, $subjects),
            pluginIds: $recipe->pluginIds(),
            resolved: $sources,
            writable: $sources !== null && $sources->writable(),
            writer: $sources !== null && $container !== null ? $sources->writer($container) : null,
            grantFields: $this->fields($recipe),
            beforeHooks: $this->slot($recipe, PanelRecipe::BEFORE, BeforeHook::class, 'before'),
            afterHooks: $this->slot($recipe, PanelRecipe::AFTER, AfterHook::class, 'after'),
            accessRestrictions: $this->components($recipe, PanelRecipe::RESTRICTIONS, Restriction::class),
            conditions: $this->components($recipe, PanelRecipe::GRANT_CONDITIONS, GrantCondition::class),
            tenantPolicy: $tenants,
            scopeDeclaration: $scopes,
            scopeDefinitions: $definitions,
            tenantResolvers: [...$this->components($recipe, PanelRecipe::TENANT_RESOLVERS, TenantResolver::class), ...$this->configured('tenants', TenantResolver::class)],
            scopeResolvers: [...$this->components($recipe, PanelRecipe::SCOPE_RESOLVERS, AssignmentScopeResolver::class), ...$this->configured('scopes', AssignmentScopeResolver::class)],
            resourceScopes: $this->resourceScopes($recipe),
            changingPipes: $this->slot($recipe, PanelRecipe::CHANGING, ChangePipe::class, 'handle'),
            changeParticipants: $this->participants($recipe, $sources, $container),
            subjectDescriptors: $subjects,
            entryPermission: $this->entry($recipe),
            deniedResponse: $this->deniedResponse($recipe),
            entryMiddleware: array_values(array_filter(self::items($recipe, PanelRecipe::MIDDLEWARE), is_string(...))),
            routeChecks: ($this->resolved($recipe, PanelRecipe::REQUIRE_ROUTE_CHECKS)['value'] ?? false) === true,
            healthChecks: $this->doctorChecks($recipe),
            options: $options,
        );
    }

    /**
     * Module options by class: for one class the provider wins over plugins, plugins over `configure`; two plugins
     * that give different objects of one class are a conflict, as with any other setting.
     *
     * @return array<class-string, object>
     *
     * @throws PluginConflictException
     */
    private function options(PanelRecipe $recipe): array
    {
        $byClass = [];

        foreach ($recipe->layered(PanelRecipe::OPTIONS) as $record) {
            if (is_object($record['value'])) {
                $byClass[$record['value']::class][] = $record;
            }
        }
        $options = [];

        foreach ($byClass as $class => $records) {
            $value = $this->pick($recipe->panelId(), 'options '.$class, $records)['value'];

            if (is_object($value)) {
                $options[$class] = $value;
            }
        }
        ksort($options, SORT_STRING);

        return $options;
    }

    /**
     * Participants of `participants()` as objects with the tables they declare. Only a panel with the core writer takes
     * participants; a participant may not declare an authority table, and two participants of one panel may not
     * declare the same table.
     *
     * @return list<array{participant: ChangeParticipant, tables: list<string>}>
     *
     * @throws DefinitionException
     */
    private function participants(PanelRecipe $recipe, ?PanelSources $sources, ?Container $container): array
    {
        $panel = $recipe->panelId();
        $participants = $owners = [];

        foreach ($recipe->layered(PanelRecipe::PARTICIPANTS) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                $where = 'Panel '.$panel.' participant '.self::describe($item).' from '.json_encode($record['origin']).': ';

                if ($sources === null || ! $sources->writable()) {
                    throw new DefinitionException($where.'participants need the database writer of the panel.');
                }
                $participant = $this->participant($where, $item, $container);
                $tables = [];

                foreach (self::declared($participant->tables()) as $table) {
                    if (! is_string($table) || $table === '') {
                        throw new DefinitionException($where.'tables() lists base names of tables, got '.self::describe($table).'.');
                    }

                    if (in_array($table, self::CORE_TABLES, true)) {
                        throw new DefinitionException($where.'the table '.$table.' belongs to the core writer.');
                    }

                    if (isset($owners[$table])) {
                        throw new DefinitionException($where.'the table '.$table.' is already declared by '.$owners[$table].'.');
                    }
                    $owners[$table] = $participant::class;
                    $tables[] = $table;
                }
                $participants[] = ['participant' => $participant, 'tables' => $tables];
            }
        }

        return $participants;
    }

    /**
     * The tables as a participant returned them: the documented type is a promise of the implementation, not a fact.
     *
     * @param  array<mixed>  $tables
     * @return array<mixed>
     */
    private static function declared(array $tables): array
    {
        return $tables;
    }

    /** @throws DefinitionException */
    private function participant(string $where, mixed $item, ?Container $container): ChangeParticipant
    {
        if ($item instanceof ChangeParticipant) {
            return $item;
        }

        if (! is_string($item) || ! is_subclass_of($item, ChangeParticipant::class)) {
            throw new DefinitionException($where.'expects '.ChangeParticipant::class.' or its class name.');
        }

        try {
            $participant = $container === null ? new $item : $container->make($item);
        } catch (Throwable $error) {
            throw new DefinitionException($where.'the participant cannot be created: '.$error->getMessage(), previous: $error);
        }

        return $participant instanceof ChangeParticipant ? $participant
            : throw new DefinitionException($where.'the container did not build a '.ChangeParticipant::class.'.');
    }

    /**
     * Doctor checks of `doctorChecks()`: an object or a class that implements `DoctorCheck`, with where it came from.
     *
     * @return list<array{check: DoctorCheck|class-string<DoctorCheck>, origin: string}>
     *
     * @throws DefinitionException
     */
    private function doctorChecks(PanelRecipe $recipe): array
    {
        $checks = [];
        foreach ($recipe->layered(PanelRecipe::DOCTOR_CHECKS) as $record) {
            $origin = $record['origin']['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$record['origin']['plugin'] : $record['origin']['kind'];
            foreach (is_array($record['value']) ? $record['value'] : [] as $check) {
                if (! $check instanceof DoctorCheck && (! is_string($check) || ! is_subclass_of($check, DoctorCheck::class))) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' expects a '.DoctorCheck::class.' object or class in doctorChecks from '
                        .json_encode($record['origin']).', got '.self::describe($check).'.');
                }
                $checks[] = ['check' => $check, 'origin' => $origin];
            }
        }

        return $checks;
    }

    /**
     * @throws PluginConflictException
     */
    private function entry(PanelRecipe $recipe): string|UnitEnum|null
    {
        $entry = $this->resolved($recipe, PanelRecipe::ENTRY)['value'] ?? null;

        return is_string($entry) || $entry instanceof UnitEnum ? $entry : null;
    }

    /**
     * @throws PluginConflictException
     */
    private function deniedResponse(PanelRecipe $recipe): Closure|string|null
    {
        $response = $this->resolved($recipe, PanelRecipe::ON_DENIED)['value'] ?? null;

        return is_string($response) || $response instanceof Closure ? $response : null;
    }

    /**
     * One descriptor per model; a guard or directory declared twice with different values is a conflict.
     *
     * @return list<SubjectDescriptor>
     *
     * @throws DefinitionException
     */
    private function subjects(PanelRecipe $recipe): array
    {
        $byModel = [];
        foreach ($recipe->subjects() as $subject) {
            $known = $byModel[$subject['model']] ?? null;

            if ($known === null) {
                $byModel[$subject['model']] = new SubjectDescriptor($subject['model'], $subject['guard'], $subject['directory']);

                continue;
            }
            foreach (['guard', 'directory'] as $setting) {
                if ($subject[$setting] !== null && $known->{$setting} !== null && $subject[$setting] !== $known->{$setting}) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' declares subject '.$subject['model'].' with two '.$setting.'s: '
                        .$known->{$setting}.' and '.$subject[$setting].'.');
                }
            }
            $byModel[$subject['model']] = new SubjectDescriptor($subject['model'], $known->guard ?? $subject['guard'], $known->directory ?? $subject['directory']);
        }

        return array_values($byModel);
    }

    /**
     * The merged scope declaration of the panel and its definitions by alias; no contribution means no declaration
     * (`AssignmentScopeMode::None`).
     *
     * The mode comes from the highest layer, the last record inside it; plugins that disagree on the mode are a
     * conflict unless the provider sets one. Definitions of every layer are merged by alias and must keep their
     * structure; directories merge by alias (a directory the merged definition names wins over the declared one) and
     * one evaluator at most is named.
     *
     * @return array{?AssignmentScopeDeclaration, array<string, AssignmentScopeDefinition>}
     *
     * @throws DefinitionException
     * @throws InvalidConfigurationException
     * @throws PluginConflictException
     */
    private function scopeDeclaration(PanelRecipe $recipe, TenantPolicy $tenants, ?Container $container): array
    {
        $panel = $recipe->panelId();
        $records = $this->scopeRecords($recipe);

        if ($records === []) {
            return [null, []];
        }
        $mode = AssignmentScopeMode::None;
        $pluginModes = [];
        $hasProvider = false;
        $declarations = [];

        foreach ($records as $record) {
            $declared = $record['value'];

            if (! $declared instanceof AssignmentScopeDeclaration) {
                throw new DefinitionException('Panel '.$panel.' requires an assignment scope declaration object.');
            }
            $hasProvider = $hasProvider || $record['origin']['kind'] === PanelRecipe::PROVIDER;

            if ($record['origin']['kind'] === PanelRecipe::PLUGIN) {
                $pluginModes[$declared->mode->value] = true;
            }
            $mode = $declared->mode;
            $declarations[] = $declared;
        }

        if (! $hasProvider && count($pluginModes) > 1) {
            throw new PluginConflictException('Plugins of panel '.$panel.' set conflicting assignment scope modes; set the mode in the panel provider.');
        }
        $evaluator = $this->scopeEvaluator($panel, $declarations);
        $definitions = $this->scopes($panel, $declarations, $tenants, $mode, $this->evaluator($panel, $evaluator, $container));

        if ($mode === AssignmentScopeMode::Required && $definitions === []) {
            throw new DefinitionException('Panel '.$panel.' requires an assignment scope but registers no definitions.');
        }

        return [new AssignmentScopeDeclaration(
            mode: $mode,
            definitions: array_values($definitions),
            directories: $this->scopeDirectories($panel, $declarations, $definitions),
            evaluator: $evaluator,
        ), $definitions];
    }

    /** @return list<Record> scope layers from lowest to highest scalar precedence */
    private function scopeRecords(PanelRecipe $recipe): array
    {
        $records = $recipe->layered(PanelRecipe::SCOPES);

        return [...array_filter($records, static fn (array $record): bool => $record['origin']['kind'] === PanelRecipe::CONFIGURE),
            ...array_filter($records, static fn (array $record): bool => $record['origin']['kind'] === PanelRecipe::PLUGIN),
            ...array_filter($records, static fn (array $record): bool => $record['origin']['kind'] === PanelRecipe::PROVIDER)];
    }

    /**
     * @param  list<AssignmentScopeDeclaration>  $declarations
     * @return array<string, AssignmentScopeDefinition>
     *
     * @throws DefinitionException
     * @throws InvalidConfigurationException
     */
    private function scopes(string $panel, array $declarations, TenantPolicy $tenants, AssignmentScopeMode $mode, ?AssignmentScopeEvaluator $evaluator): array
    {
        $compiled = [];

        foreach ($declarations as $declaration) {
            foreach (PanelCatalog::untrusted($declaration->definitions) as $definition) {
                if (! $definition instanceof AssignmentScopeDefinition) {
                    throw new DefinitionException('Panel '.$panel.' requires assignment scope definition objects in its scope declaration, got '.self::describe($definition).'.');
                }

                try {
                    IdentityCodec::assertTypeAlias($definition->type());
                } catch (Throwable $error) {
                    throw new DefinitionException('Panel '.$panel.' has an invalid assignment scope type.', previous: $error);
                }
                $model = $definition->model();

                if ($model !== null && ! is_subclass_of($model, Model::class)) {
                    throw new DefinitionException('Panel '.$panel.' scope '.$definition->type().' declares an invalid Eloquent model.');
                }

                ScopeConfiguration::tenantOwner($panel, $definition, $tenants->mode());

                if ($evaluator === null && $mode !== AssignmentScopeMode::None && ScopeConfiguration::filterMetadata($definition) !== []) {
                    throw new DefinitionException('Panel '.$panel.' declares filters on assignment scope '.$definition->type().' without an evaluator that applies them.');
                }
                $evaluator?->validate($definition, null);
                $existing = $compiled[$definition->type()] ?? null;

                if ($existing !== null && ! ScopeConfiguration::sameStructure($existing, $definition)) {
                    throw new DefinitionException('Panel '.$panel.' declares conflicting assignment scope definitions for '.$definition->type().'.');
                }
                $compiled[$definition->type()] = $existing === null ? $definition : ScopeConfiguration::merge($existing, $definition);
            }
        }

        return $compiled;
    }

    /**
     * The evaluator of the declaration for its build-time validation; without a container nothing is resolved and the
     * definitions are checked structurally only.
     *
     * @param  class-string<AssignmentScopeEvaluator>|null  $class
     *
     * @throws DefinitionException
     */
    private function evaluator(string $panel, ?string $class, ?Container $container): ?AssignmentScopeEvaluator
    {
        if ($class === null || $container === null) {
            return null;
        }
        $evaluator = $container->make($class);

        if (! $evaluator instanceof AssignmentScopeEvaluator) {
            throw new DefinitionException('Panel '.$panel.' assignment scope evaluator '.$class.' did not resolve to '.AssignmentScopeEvaluator::class.'.');
        }

        return $evaluator;
    }

    /**
     * @param  list<AssignmentScopeDeclaration>  $declarations
     * @param  array<string, AssignmentScopeDefinition>  $definitions
     * @return array<string, class-string<AssignmentScopeDirectory>>
     *
     * @throws DefinitionException
     */
    private function scopeDirectories(string $panel, array $declarations, array $definitions): array
    {
        $directories = [];

        foreach ($declarations as $declaration) {
            foreach (PanelCatalog::untrusted($declaration->directories) as $alias => $directory) {
                if (! is_string($alias) || ! is_string($directory) || ! is_subclass_of($directory, AssignmentScopeDirectory::class)) {
                    throw new DefinitionException('Panel '.$panel.' declares assignment scope directories as classes implementing '
                        .AssignmentScopeDirectory::class.' keyed by scope type, got '.self::describe($directory).' for '.self::describe($alias).'.');
                }

                if (! isset($definitions[$alias])) {
                    throw new DefinitionException('Panel '.$panel.' declares a directory for unknown assignment scope '.$alias.'.');
                }

                if (isset($directories[$alias]) && $directories[$alias] !== $directory) {
                    throw new DefinitionException('Panel '.$panel.' declares conflicting directories for assignment scope '.$alias.': '.$directories[$alias].' and '.$directory.'.');
                }
                $directories[$alias] = $directory;
            }
        }

        // The directory a merged definition names is the one of its type: the declared classes are the defaults.
        foreach ($definitions as $alias => $definition) {
            $own = ScopeConfiguration::directory($definition);

            if ($own !== null && ! is_subclass_of($own, AssignmentScopeDirectory::class)) {
                throw new DefinitionException('Panel '.$panel.' assignment scope '.$alias.' names a directory '.$own.' that does not implement '.AssignmentScopeDirectory::class.'.');
            }

            if ($own !== null) {
                $directories[$alias] = $own;
            }
        }

        return $directories;
    }

    /**
     * @param  list<AssignmentScopeDeclaration>  $declarations
     * @return class-string<AssignmentScopeEvaluator>|null
     *
     * @throws DefinitionException
     */
    private function scopeEvaluator(string $panel, array $declarations): ?string
    {
        $evaluator = null;

        foreach ($declarations as $declaration) {
            if ($declaration->evaluator === null) {
                continue;
            }

            if (! is_subclass_of($declaration->evaluator, AssignmentScopeEvaluator::class)) {
                throw new DefinitionException('Panel '.$panel.' names an assignment scope evaluator '.$declaration->evaluator.' that does not implement '
                    .AssignmentScopeEvaluator::class.'.');
            }

            if ($evaluator !== null && $evaluator !== $declaration->evaluator) {
                throw new DefinitionException('Panel '.$panel.' declares conflicting assignment scope evaluators '.$evaluator.' and '.$declaration->evaluator.'.');
            }
            $evaluator = $declaration->evaluator;
        }

        return $evaluator;
    }

    /** @param object|class-string|null $adapter */
    private function membership(object|string|null $adapter, ?Container $container, string $check, string $kind): void
    {
        if (is_string($adapter) && ! ($container?->bound($adapter) ?? false) && ! (new ReflectionClass($adapter))->isInstantiable()) {
            throw InvalidConfigurationException::failing($check, $kind.' membership adapter '.$adapter.' has no container binding: bind it or name a class that can be created.');
        }
    }

    /** @return array<class-string<Model>, ResourceScopeResolver|class-string<ResourceScopeResolver>> */
    private function resourceScopes(PanelRecipe $recipe): array
    {
        $compiled = [];
        foreach ($recipe->items(PanelRecipe::RESOURCE_SCOPES) as $item) {
            if (! is_array($item) || ! is_string($item['resource'] ?? null) || ! isset($item['resolver'])) {
                throw new DefinitionException('Panel '.$recipe->panelId().' has an invalid resource scope resolver binding.');
            }
            $resource = $item['resource'];
            $resolver = $item['resolver'];

            if (! is_subclass_of($resource, Model::class) || (! $resolver instanceof ResourceScopeResolver
                && (! is_string($resolver) || ! is_subclass_of($resolver, ResourceScopeResolver::class)))) {
                throw new DefinitionException('Panel '.$recipe->panelId().' requires resource models and authoritative resource scope resolvers.');
            }

            if (isset($compiled[$resource]) && $compiled[$resource] != $resolver) {
                throw new DefinitionException('Panel '.$recipe->panelId().' declares conflicting resource scope resolvers for '.$resource.'.');
            }
            $compiled[$resource] = $resolver;
        }

        return $compiled;
    }

    /**
     * The entries of a hook or pipe slot: an object or a class of the slot's contract, or a closure whose parameters
     * and return type fit the contract method. Anything else fails here, not at the first request.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  non-empty-string  $method  the one method of the contract a closure stands for
     * @return list<T|class-string<T>|Closure>
     *
     * @throws DefinitionException
     */
    private function slot(PanelRecipe $recipe, string $setting, string $contract, string $method): array
    {
        $entries = [];
        foreach ($recipe->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                $where = 'Panel '.$recipe->panelId().' slot `'.$setting.'` from '.json_encode($record['origin']).': ';

                if ($item instanceof Closure) {
                    $mismatch = self::closureMismatch($item, $contract, $method);

                    if ($mismatch !== null) {
                        throw new DefinitionException($where.'a closure must match '.$contract.'::'.$method.'(), but '.$mismatch.'.');
                    }
                } elseif (! $item instanceof $contract && (! is_string($item) || ! is_subclass_of($item, $contract))) {
                    throw new DefinitionException($where.'expects '.$contract.', its class name or a closure with the signature of '.$contract.'::'.$method.'(), got '.self::describe($item).'.');
                }
                $entries[] = $item;
            }
        }

        return $entries;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return list<T|class-string<T>>
     */
    private function components(PanelRecipe $recipe, string $setting, string $contract): array
    {
        $components = [];
        foreach ($recipe->layered($setting) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                if (! $item instanceof $contract && (! is_string($item) || ! is_subclass_of($item, $contract))) {
                    throw new DefinitionException('Panel '.$recipe->panelId().' expects '.$contract.' in '.$setting.' from '.json_encode($record['origin']).'.');
                }
                $components[] = $item;
            }
        }

        return $components;
    }

    /**
     * Resolvers the configuration lists for every panel.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return list<class-string<T>>
     *
     * @throws InvalidConfigurationException
     */
    private function configured(string $group, string $contract): array
    {
        $configured = [];

        foreach ($this->resolvers === null ? [] : ($this->resolvers)()[$group] as $class) {
            if (! is_subclass_of($class, $contract)) {
                throw new InvalidConfigurationException('azguard.defaults.'.$group.'.resolvers lists '.$class.', which must implement '.$contract.'.');
            }

            $configured[] = $class;
        }

        return $configured;
    }

    /** @return array<string, list<Field>> */
    private function fields(PanelRecipe $recipe): array
    {
        $fields = [];
        foreach (FieldTarget::cases() as $target) {
            $byName = [];
            foreach ($recipe->layered(PanelRecipe::FIELDS.'.'.$target->value) as $record) {
                $origin = $record['origin']['kind'];

                if ($record['origin']['plugin'] !== null) {
                    $origin .= ':'.$record['origin']['plugin'];
                }
                foreach (is_array($record['value']) ? $record['value'] : [] as $field) {
                    if (! $field instanceof Field) {
                        throw new DefinitionException('Panel '.$recipe->panelId().' expects Field objects.');
                    }
                    $name = $field->name();

                    if (isset($byName[$name])) {
                        throw new DefinitionException('Panel '.$recipe->panelId().' has duplicate '.$target->value.' field '.$name.' from '
                            .$byName[$name]->contributedBy().' and '.$origin.'.');
                    }
                    $byName[$name] = $field->withContribution($origin);
                }
            }
            $fields[$target->value] = array_values($byName);
        }

        return $fields;
    }

    /**
     * Builds the static catalog of a compiled panel from the sources of its recipe.
     *
     * @throws DefinitionException
     */
    public function catalog(Panel $panel, PanelRecipe $recipe, Container $container): PanelCatalog
    {
        return PanelCatalog::build($panel, PanelSources::of($recipe, $container), $container);
    }

    /**
     * @throws PluginConflictException
     * @throws InvalidConfigurationException when a value is outside its enum or range, in every environment
     */
    public function settings(PanelRecipe $recipe): PanelSettings
    {
        $defaults = [...PanelSettings::DEFAULTS, ...($this->defaults === null ? [] : ($this->defaults)())];
        $values = $origins = [];

        foreach (array_keys(PanelSettings::DEFAULTS) as $setting) {
            $resolved = $this->resolved($recipe, $setting);
            $values[$setting] = $resolved === null ? $defaults[$setting] : $resolved['value'];
            $origins[$setting] = $resolved === null ? 'default' : $resolved['origin'];
        }

        $panel = $recipe->panelId();
        $prefix = $values[PanelSettings::RESOURCE_PREFIX];
        $store = $values[PanelSettings::CACHE_STORE];
        $ttl = $values[PanelSettings::CACHE_TTL];
        $generation = $values[PanelSettings::CACHE_GENERATION];

        if ((! is_int($ttl) && $ttl !== null) || (is_int($ttl) && $ttl < 1)) {
            throw self::invalid('enum', $panel, PanelSettings::CACHE_TTL, $ttl, 'a positive number of seconds or null');
        }

        if (! is_int($generation) || $generation < 1) {
            throw self::invalid('enum', $panel, PanelSettings::CACHE_GENERATION, $generation, 'a positive integer');
        }

        if ($store !== null && $ttl === null) {
            throw InvalidConfigurationException::failing(
                'cache_ttl',
                'Panel "'.$panel.'" caches permission sets in the store '.self::describe($store).' without a ttl: set cache.ttl.',
            );
        }

        return new PanelSettings(
            resourcePrefix: match (true) {
                is_string($prefix) => $prefix,
                $prefix === false => null,
                default => $panel,
            },
            cacheStore: is_string($store) ? $store : null,
            cacheTtl: $ttl,
            cacheGeneration: $generation,
            reads: self::enum(Reads::class, $panel, PanelSettings::READS, $values[PanelSettings::READS]),
            stateRefresh: self::enum(StateRefresh::class, $panel, PanelSettings::STATE_REFRESH, $values[PanelSettings::STATE_REFRESH]),
            traceDecisions: $values[PanelSettings::TRACE_DECISIONS] === true,
            origins: $origins,
        );
    }

    /**
     * Presentation options merged by top-level key with the precedence of scalar settings.
     *
     * @return array<string, mixed>
     *
     * @throws PluginConflictException
     */
    public function presentation(PanelRecipe $recipe): array
    {
        $byKey = [];

        foreach ($recipe->layered(PanelRecipe::PRESENTATION) as $record) {
            foreach (is_array($record['value']) ? $record['value'] : [] as $key => $value) {
                $byKey[(string) $key][] = ['setting' => $record['setting'], 'value' => $value, 'origin' => $record['origin']];
            }
        }

        $options = [];

        foreach ($byKey as $key => $records) {
            $options[$key] = $this->pick($recipe->panelId(), 'presentation.'.$key, $records)['value'];
        }

        return $options;
    }

    /**
     * Items of a list setting from every layer; a class named twice is kept once, at its first position.
     *
     * @return list<mixed>
     */
    public static function items(PanelRecipe $recipe, string $setting): array
    {
        $items = $seen = [];

        foreach ($recipe->items($setting) as $item) {
            if (is_string($item)) {
                if (isset($seen[$item])) {
                    continue;
                }

                $seen[$item] = true;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, Panel>  $panels
     *
     * @throws DefaultPanelConflictException when two default panels share a subject model
     */
    public function assertDefaults(array $panels): void
    {
        $defaults = array_values(array_filter($panels, static fn (Panel $panel): bool => $panel->isDefault()));

        foreach ($defaults as $position => $first) {
            foreach (array_slice($defaults, $position + 1) as $second) {
                $shared = $this->sharedModel($first, $second);

                if ($shared !== null) {
                    throw new DefaultPanelConflictException(
                        'Panels "'.$first->id().'" and "'.$second->id().'" are both the default panel of '.$shared
                        .': keep default() on one of them.',
                    );
                }
            }
        }
    }

    /**
     * The prefix dictionary of the application: a prefix is one segment that names exactly one panel.
     *
     * A prefix never equals the first segment of a static permission name of any panel, plugin contributions included:
     * such a name would read as a name with the prefix.
     *
     * @param  array<string, Panel>  $panels
     * @param  array<string, PanelCatalog>  $catalogs
     * @return array<string, string> prefix => panel id
     *
     * @throws PrefixConflictException when two panels resolve to the same prefix, the default one included, or a
     *                                 prefix is the first segment of a permission name
     */
    public function prefixes(array $panels, array $catalogs = []): array
    {
        $prefixes = [];

        foreach ($panels as $panel) {
            $prefix = $panel->prefix();

            if ($prefix === null) {
                continue;
            }

            if (isset($prefixes[$prefix])) {
                throw new PrefixConflictException(
                    'Panels "'.$prefixes[$prefix].'" and "'.$panel->id().'" both use the permission prefix "'.$prefix
                    .'": give one of them another resourcePrefix() or turn it off with resourcePrefix(false).',
                );
            }

            $prefixes[$prefix] = $panel->id();
        }

        foreach ($catalogs as $catalog) {
            foreach ($prefixes as $prefix => $owner) {
                $name = $catalog->nameWithFirstSegment((string) $prefix);

                if ($name !== null) {
                    throw new PrefixConflictException(
                        'The permission prefix "'.$prefix.'" of panel "'.$owner.'" is the first segment of the permission "'.$name
                        .'" of panel "'.$catalog->panel().'": give the panel another resourcePrefix() or rename the permission.',
                    );
                }
            }
        }

        return $prefixes;
    }

    /**
     * The copy of a plugin one panel works with.
     *
     * @param  Plugin|class-string<Plugin>  $declared
     *
     * @throws DefinitionException when the container gives something that is not a plugin
     */
    private function instance(string $panel, Plugin|string $declared, Container $container): Plugin
    {
        if ($declared instanceof Plugin) {
            return clone $declared;
        }

        $plugin = $container->make($declared);

        if (! $plugin instanceof Plugin) {
            throw new DefinitionException(
                'Panel "'.$panel.'": the container resolved '.$declared.' to '.get_debug_type($plugin).', which is not a plugin.',
            );
        }

        return clone $plugin;
    }

    /**
     * @throws DefinitionException
     */
    private function pluginId(string $panel, Plugin $plugin): string
    {
        $id = $plugin->id();

        if (strlen($id) > self::PLUGIN_ID_BYTES || preg_match(self::PLUGIN_ID, $id) !== 1) {
            throw new DefinitionException(
                'Panel "'.$panel.'": '.$plugin::class.' has the plugin id '.self::describe($id).'; an id is "name" or "vendor/name" of '
                .'lowercase letters, digits, ".", "_" and "-", at most '.self::PLUGIN_ID_BYTES.' bytes.',
            );
        }

        return $id;
    }

    /**
     * The prefix `azguard/` belongs to the plugins of this package: a class outside the namespace `AzGuard\` cannot
     * take an id under it. An anonymous class is named after its interface, so it never counts as a class of the package.
     *
     * @throws PluginConflictException
     */
    private function assertIdOwned(string $panel, string $id, Plugin $plugin): void
    {
        if (! str_starts_with($id, 'azguard/')) {
            return;
        }

        if ((new ReflectionClass($plugin))->isAnonymous() || ! str_starts_with($plugin::class, 'AzGuard\\')) {
            throw new PluginConflictException(
                'Panel "'.$panel.'": '.$plugin::class.' has the plugin id "'.$id.'", but the prefix "azguard/" is reserved for plugins of the package; use your own vendor name.',
            );
        }
    }

    /**
     * The effective value of a scalar setting and its origin, or null when no layer of the recipe sets it.
     *
     * @return Resolved|null
     *
     * @throws PluginConflictException
     */
    private function resolved(PanelRecipe $recipe, string $setting): ?array
    {
        $records = $recipe->layered($setting);

        return $records === [] ? null : $this->pick($recipe->panelId(), $setting, $records);
    }

    /**
     * @param  non-empty-list<Record>  $records  records of one setting in layer order
     * @return Resolved
     *
     * @throws PluginConflictException
     */
    private function pick(string $panel, string $setting, array $records): array
    {
        $layers = [PanelRecipe::PROVIDER => [], PanelRecipe::PLUGIN => [], PanelRecipe::CONFIGURE => []];

        foreach ($records as $record) {
            $layers[$record['origin']['kind']][] = $record;
        }

        if ($layers[PanelRecipe::PROVIDER] !== []) {
            return ['value' => end($layers[PanelRecipe::PROVIDER])['value'], 'origin' => PanelRecipe::PROVIDER];
        }

        if ($layers[PanelRecipe::PLUGIN] === []) {
            return ['value' => end($layers[PanelRecipe::CONFIGURE])['value'] ?? null, 'origin' => PanelRecipe::CONFIGURE];
        }

        $byPlugin = [];

        foreach ($layers[PanelRecipe::PLUGIN] as $record) {
            $byPlugin[(string) $record['origin']['plugin']] = $record['value'];
        }

        $first = array_key_first($byPlugin);

        foreach ($byPlugin as $plugin => $value) {
            $same = ($value instanceof TenantPolicy || (is_object($value) && ! $value instanceof Closure))
                ? $value == $byPlugin[$first]
                : $value === $byPlugin[$first];

            if (! $same) {
                throw new PluginConflictException(
                    'Plugins "'.$first.'" and "'.$plugin.'" of panel "'.$panel.'" set "'.$setting.'" to different values ('
                    .self::describe($byPlugin[$first]).' and '.self::describe($value).'): set it in the panel provider.',
                );
            }
        }

        return ['value' => $byPlugin[$first], 'origin' => PanelRecipe::PLUGIN.':'.$first];
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     *
     * @throws InvalidConfigurationException
     */
    private static function enum(string $enum, string $panel, string $setting, mixed $value): BackedEnum
    {
        $case = match (true) {
            $value instanceof $enum => $value,
            is_string($value) => $enum::tryFrom($value),
            default => null,
        };

        return $case ?? throw self::invalid(
            'enum',
            $panel,
            $setting,
            $value,
            'one of '.implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
        );
    }

    private static function invalid(string $check, string $panel, string $setting, mixed $value, string $expected): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing(
            $check,
            'Panel "'.$panel.'": setting "'.$setting.'" is '.self::describe($value).', expected '.$expected.'.',
        );
    }

    /**
     * Checks a closure given to a hook or pipe slot against the one method of the slot's contract: the same number of
     * parameters, each declared with a type that accepts what the contract passes, and a declared return type that the
     * contract accepts. A closure that passes is called positionally, never by parameter name.
     *
     * @param  class-string  $contract
     * @return string|null what does not match, or null when the closure fits the method
     */
    private static function closureMismatch(Closure $closure, string $contract, string $method): ?string
    {
        $expected = new ReflectionMethod($contract, $method);
        $actual = new ReflectionFunction($closure);
        $parameters = $expected->getParameters();

        if ($actual->getNumberOfParameters() !== count($parameters)) {
            return 'it takes '.$actual->getNumberOfParameters().' parameters, the contract has '.count($parameters);
        }

        foreach ($actual->getParameters() as $position => $parameter) {
            $problem = self::slotParameter($parameter, $parameters[$position]);

            if ($problem !== null) {
                return 'parameter '.($position + 1).' '.$problem;
            }
        }

        return self::slotReturn($actual->getReturnType(), $expected->getReturnType());
    }

    private static function slotParameter(ReflectionParameter $declared, ReflectionParameter $expected): ?string
    {
        $wanted = (string) $expected->getType();
        $type = $declared->getType();

        if ($type === null) {
            return 'has no declared type, the contract passes '.$wanted;
        }

        if ($declared->isVariadic() || ! self::slotAccepts($type, (string) $expected->getType())) {
            return 'is declared '.$type.', the contract passes '.$wanted;
        }

        return null;
    }

    private static function slotReturn(?ReflectionType $declared, ?ReflectionType $expected): ?string
    {
        $wanted = (string) $expected;

        if ($declared === null) {
            return 'the return type is not declared, the contract returns '.$wanted;
        }

        foreach (self::slotMembers($declared) as $member) {
            if (! self::slotSubtype($member, $wanted)) {
                return 'the return type is '.$declared.', the contract returns '.$wanted;
            }
        }

        return null;
    }

    /** Whether a parameter of the declared type takes a value of the type the contract passes. */
    private static function slotAccepts(ReflectionType $declared, string $passed): bool
    {
        if ($declared instanceof ReflectionIntersectionType) {
            foreach ($declared->getTypes() as $member) {
                if (! self::slotAccepts($member, $passed)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::slotMembers($declared) as $member) {
            if (self::slotSubtype($passed, $member)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function slotMembers(ReflectionType $type): array
    {
        if ($type instanceof ReflectionUnionType) {
            return array_values(array_map(static fn (ReflectionType $member): string => (string) $member, $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null') {
            return [$type->getName(), 'null'];
        }

        return [(string) $type];
    }

    /** Whether every value of type `$sub` is a value of type `$super`. */
    private static function slotSubtype(string $sub, string $super): bool
    {
        $sub = ltrim($sub, '?\\');
        $super = ltrim($super, '?\\');

        if ($sub === $super || $super === 'mixed' || $sub === 'never') {
            return true;
        }

        if ($super === 'callable') {
            return $sub === Closure::class;
        }

        if ($super === 'object') {
            return class_exists($sub) || interface_exists($sub) || enum_exists($sub);
        }

        return (class_exists($sub) || interface_exists($sub) || enum_exists($sub)) && is_a($sub, $super, true);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) || $value === null => (string) json_encode($value),
            default => get_debug_type($value),
        };
    }

    private function sharedModel(Panel $first, Panel $second): ?string
    {
        foreach ($first->subjectModels() as $model) {
            foreach ($second->subjectModels() as $other) {
                if (is_a($model, $other, true) || is_a($other, $model, true)) {
                    return is_a($model, $other, true) ? $model : $other;
                }
            }
        }

        return null;
    }
}
