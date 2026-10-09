<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable Eloquent assignment scope. A concrete class declares `type()`, `query()` and `tenantOf()`;
 * this base has no universal `make()`.
 *
 * `resolve()` loads the record once. A global reference and a reference of another type are refused, not ignored.
 *
 * @spi
 */
abstract class BaseAssignmentScope implements ConfigurableAssignmentScopeDefinition, QueryableAssignmentScopeDefinition
{
    /** @var list<AssignmentScopeFilter|class-string<AssignmentScopeFilter>|Closure> */
    private array $filters = [];

    private ?string $label = null;

    /** @var class-string<AssignmentScopeDirectory>|null */
    private ?string $directory = null;

    abstract public function type(): string;

    abstract public function query(): Builder;

    abstract public function tenantOf(Model $record): TenantRef;

    public function model(): ?string
    {
        return $this->query()->getModel()::class;
    }

    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope
    {
        if ($ref->isGlobal() || $ref->type() !== $this->type()) {
            throw new InvalidAssignmentScopeException(
                'Assignment scope '.static::class.' accepts type "'.$this->type().'" and refuses '.($ref->isGlobal()
                    ? 'the global scope'
                    : 'type "'.$ref->type().'"').': a foreign reference is not treated as missing.',
            );
        }

        $record = ModelKey::find($this->query(), $ref->id());

        return $record === null ? null : new ResolvedAssignmentScope($ref, $this->tenantOf($record), $record);
    }

    public function filter(AssignmentScopeFilter|string|Closure $filter): static
    {
        if (is_string($filter)) {
            $filter = $this->filterClass($filter);
        }

        $copy = clone $this;
        $copy->filters[] = $filter;

        return $copy;
    }

    public function label(string $label): static
    {
        $copy = clone $this;
        $copy->label = $label;

        return $copy;
    }

    public function directory(string $class): static
    {
        $class = $this->directoryClass($class);

        $copy = clone $this;
        $copy->directory = $class;

        return $copy;
    }

    /**
     * The public signature names a filter class. This check accepts the raw string a caller can still pass.
     *
     * @param  class-string  $class
     * @return class-string<AssignmentScopeFilter>
     */
    private function filterClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeFilter::class, true)) {
            throw new DefinitionException(
                static::class.'::filter() expects an '.AssignmentScopeFilter::class.' object, its class or a closure, got '
                .json_encode($class).'.',
            );
        }

        return $class;
    }

    /**
     * The public signature names a directory class. This check accepts the raw string a caller can still pass.
     *
     * @param  class-string  $class
     * @return class-string<AssignmentScopeDirectory>
     */
    private function directoryClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeDirectory::class, true)) {
            throw new DefinitionException(
                static::class.'::directory() expects a class that implements '.AssignmentScopeDirectory::class.', got '
                .json_encode($class).'.',
            );
        }

        return $class;
    }

    public function settings(): AssignmentScopeSettings
    {
        return new AssignmentScopeSettings($this->filters, $this->label, $this->directory);
    }
}
