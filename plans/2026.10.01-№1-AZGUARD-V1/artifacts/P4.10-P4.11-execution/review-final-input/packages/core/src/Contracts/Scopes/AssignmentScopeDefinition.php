<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Database\Eloquent\Model;

/**
 * A type of assignment scope, such as a project; grants store its alias, never the class name.
 *
 * @spi
 */
interface AssignmentScopeDefinition
{
    /**
     * Stable registered alias, unique within a panel.
     */
    public function type(): string;

    /**
     * @return class-string<Model>|null
     */
    public function model(): ?string;

    /**
     * The scope and its tenant, `null` when it does not exist; an exception means refusal.
     */
    public function resolve(AssignmentScopeRef $ref): ?ResolvedAssignmentScope;
}
