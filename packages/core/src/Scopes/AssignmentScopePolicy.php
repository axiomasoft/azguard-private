<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\IdentityCodec;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The assignment scopes accepted by a panel and how tenant-wide grants inherit into them.
 *
 * @api
 */
final class AssignmentScopePolicy
{
    /** @var AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null */
    private AssignmentScopeMembership|string|null $membership = null;

    /** @var array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>> */
    private array $adapters = [];

    /** @param list<AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model>> $definitions */
    private function __construct(private readonly string $mode, private readonly array $definitions) {}

    public static function none(): self
    {
        return new self(mode: 'none', definitions: []);
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function inherit(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: 'inherit', definitions: array_values($definitions));
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function isolated(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: 'isolated', definitions: array_values($definitions));
    }

    /** @param AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model> ...$definitions */
    public static function required(AssignmentScopeDefinition|string ...$definitions): self
    {
        return new self(mode: 'required', definitions: array_values($definitions));
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

    /** @return class-string<AssignmentScopeAccessAdapter> */
    private function adapterClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeAccessAdapter::class, true)) {
            throw new DefinitionException('Assignment scope access adapter must implement '.AssignmentScopeAccessAdapter::class.'.');
        }

        return $class;
    }

    /** @return array<string, AssignmentScopeAccessAdapter|class-string<AssignmentScopeAccessAdapter>> */
    public function adapters(): array
    {
        return $this->adapters;
    }

    /** @return class-string<AssignmentScopeMembership> */
    private function membershipClass(string $class): string
    {
        if (! is_a($class, AssignmentScopeMembership::class, true)) {
            throw new DefinitionException('Assignment scope membership must implement '.AssignmentScopeMembership::class.'.');
        }

        return $class;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** @return list<AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>|class-string<Model>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return AssignmentScopeMembership|class-string<AssignmentScopeMembership>|null */
    public function membership(): AssignmentScopeMembership|string|null
    {
        return $this->membership;
    }
}
