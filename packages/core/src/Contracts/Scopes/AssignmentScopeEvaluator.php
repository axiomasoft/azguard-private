<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Exceptions\DefinitionException;

/**
 * Decides scope eligibility for the panel that declares it. Core calls it only after it verified the scope
 * structurally (declared type, `resolve()`, reference, owner tenant, record); an answer can only narrow, never grant.
 *
 * Resolved from the container for each operation; the settings of a panel are read from the runtime's panel.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AssignmentScopeEvaluator
{
    /**
     * Compile time, once per declared definition and per role binding of that alias.
     *
     * @throws DefinitionException
     */
    public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void;

    /** Eligibility of a structurally verified scope; true never overrides tenant, identity or mode checks. */
    public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool;

    /**
     * @param  list<array{AssignmentScopeRuntime, ResolvedAssignmentScope}>  $checks
     * @return list<bool> one answer per check, same order
     */
    public function allowsMany(array $checks): array;
}
