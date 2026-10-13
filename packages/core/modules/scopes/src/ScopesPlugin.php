<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Attributes\Api;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\PluginContext;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Internal\Checks\ScopeMembershipConfigured;
use AzGuard\Scopes\Internal\QueryScopeDirectory;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Scopes\Internal\ScopeMembershipRestriction;
use AzGuard\Scopes\Internal\ScopesOptions;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Throwable;

/**
 * The assignment scopes a panel accepts and how tenant-wide grants inherit into them: attach
 * `ScopesPlugin::inherit(Project::class)` to a panel. A panel without it accepts global requests only.
 *
 * A definition is an object, a definition class that can be created without arguments, or an Eloquent model class
 * (a model-backed definition of that model). Pass a definition that needs services as an object.
 */
#[Api(since: '1.0')]
final class ScopesPlugin implements Plugin
{
    /** @var AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null */
    private AssignmentScopeMembership|string|null $membership = null;

    /** @var array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>> */
    private array $adapters = [];

    /** @param list<AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model>> $definitions */
    private function __construct(private readonly AssignmentScopeMode $mode, private readonly array $definitions) {}

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function inherit(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: AssignmentScopeMode::Inherit, definitions: array_values($definitions));
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function isolated(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: AssignmentScopeMode::Isolated, definitions: array_values($definitions));
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function required(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: AssignmentScopeMode::Required, definitions: array_values($definitions));
    }

    /** @param AssignmentScopeMembership|class-string<AssignmentScopeMembership> $membership */
    public function requireMembership(AssignmentScopeMembership|string $membership = AssignmentScopeMembership::class): self
    {
        if (is_string($membership)) {
            $membership = $this->membershipClass($membership);
        }

        $copy = clone $this;
        $copy->membership = $membership;

        return $copy;
    }

    /** @param AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter> $adapter */
    public function accessAdapter(string $type, AssignmentScopeAccessAdapter|string $adapter): self
    {
        try {
            IdentityCodec::assertTypeAlias($type);
        } catch (Throwable $error) {
            throw new DefinitionException('Assignment scope access adapter requires a valid type alias.', previous: $error);
        }

        if (is_string($adapter)) {
            $adapter = $this->adapterClass($adapter);
        }

        $copy = clone $this;
        $copy->adapters[$type] = $adapter;

        return $copy;
    }

    public function id(): string
    {
        return 'azguard/scopes';
    }

    /**
     * Contributes the scope declaration (mode, definitions, `QueryScopeDirectory` for each queryable definition — a
     * directory the definition names takes precedence — and `ScopeEvaluator`), the scope options (membership, access
     * adapters), the scope membership restriction when a membership is required and the doctor check
     * `scopes.membership`.
     *
     * @throws DefinitionException when a definition is neither a definition object, a definition class that can be
     *                             created without arguments nor an Eloquent model class, when the membership or an
     *                             access adapter does not implement its contract, or when an access adapter names a
     *                             type that is not one of these definitions
     */
    public function register(PanelBuilder $panel, PluginContext $context): void
    {
        $definitions = array_map(static fn (AssignmentScopeDefinition|string $declared): AssignmentScopeDefinition => self::definition($context, $declared), $this->definitions);
        $types = array_map(static fn (AssignmentScopeDefinition $definition): string => $definition->type(), $definitions);

        if ($this->membership !== null) {
            self::implements($this->membership, AssignmentScopeMembership::class, 'Assignment scope membership');
        }

        // Whether a class name can be created from the container is reported by the `scopes.membership` doctor check.
        foreach ($this->adapters as $type => $adapter) {
            self::implements($adapter, AssignmentScopeAccessAdapter::class, 'Assignment scope access adapter');

            if (! in_array($type, $types, true)) {
                throw new DefinitionException('Panel '.$context->panelId().' registers an access adapter for unknown assignment scope '.$type.'.');
            }
        }

        $directories = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof QueryableAssignmentScopeDefinition) {
                $directories[$definition->type()] = QueryScopeDirectory::class;
            }
        }

        $panel->assignmentScopes(new AssignmentScopeDeclaration($this->mode, $definitions, $directories, ScopeEvaluator::class))
            ->options(new ScopesOptions($this->membership, $this->adapters))
            ->doctorChecks([ScopeMembershipConfigured::class]);

        if ($this->membership !== null) {
            $panel->restrictions([ScopeMembershipRestriction::class]);
        }
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> $declared */
    private static function definition(PluginContext $context, AssignmentScopeDefinition|string $declared): AssignmentScopeDefinition
    {
        if ($declared instanceof AssignmentScopeDefinition) {
            return $declared;
        }

        if (is_subclass_of($declared, Model::class)) {
            return ModelAssignmentScopeDefinition::make($declared);
        }

        if (! is_subclass_of($declared, AssignmentScopeDefinition::class)) {
            throw new DefinitionException('Panel '.$context->panelId().' requires assignment scope definition objects or classes, or Eloquent model classes; got '.$declared.'.');
        }
        $reflection = new ReflectionClass($declared);

        if (! $reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new DefinitionException('Panel '.$context->panelId().' cannot create assignment scope definition '.$declared.' without arguments: pass a definition object.');
        }

        return $reflection->newInstance();
    }

    /**
     * @param  class-string  $contract
     *
     * @throws DefinitionException
     */
    private static function implements(object|string $component, string $contract, string $label): void
    {
        if ($component instanceof $contract || (is_string($component) && is_a($component, $contract, true))) {
            return;
        }

        throw new DefinitionException($label.' must implement '.$contract.'; the configured class may have been removed.');
    }

    /** @return class-string<AssignmentScopeAccessAdapter> */
    private function adapterClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeAccessAdapter::class, true)) {
            throw new DefinitionException('Assignment scope access adapter must implement '.AssignmentScopeAccessAdapter::class.'.');
        }

        return $class;
    }

    /** @return class-string<AssignmentScopeMembership> */
    private function membershipClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeMembership::class, true)) {
            throw new DefinitionException('Assignment scope membership must implement '.AssignmentScopeMembership::class.'.');
        }

        return $class;
    }
}
