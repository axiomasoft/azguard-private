<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Roles\BaseRole;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * The bindings of a role to one registered assignment scope type, shared by eligibility checks and directories.
 *
 * @internal
 */
final class RoleBindings
{
    /**
     * Bindings of the role for the type of the registered definition, empty when the role is not granted in it.
     * A binding may refine filters, label and directory, never the registered identity.
     *
     * @return list<AssignmentScopeDefinition>
     */
    public static function of(BaseRole $role, AssignmentScopeDefinition $definition, Container $container): array
    {
        $bindings = [];

        foreach ($role->scopes() as $declared) {
            $binding = is_string($declared) ? $container->make($declared) : $declared;

            if (! $binding instanceof AssignmentScopeDefinition) {
                throw new RuntimeException('A runtime role binding must be an assignment scope definition.');
            }

            if ($binding->type() !== $definition->type()) {
                continue;
            }

            if ($binding::class !== $definition::class || $binding->model() !== $definition->model()) {
                throw new RuntimeException('A runtime role binding changed the registered scope identity.');
            }

            $bindings[] = $binding;
        }

        return $bindings;
    }
}
