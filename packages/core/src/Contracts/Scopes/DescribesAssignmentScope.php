<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Context\AssignmentScopeDescription;

/**
 * An assignment-scope definition that tells core its configuration facts: the components only the panel's evaluator
 * applies, label, default directory, whether records have a tenant owner and whether `resolve()` equals `query()` plus
 * `tenantOf()`. A definition without it is described by the defaults of {@see AssignmentScopeDescription}.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DescribesAssignmentScope extends AssignmentScopeDefinition
{
    /** Facts core needs about this definition; pure, no I/O, same answer for the lifetime of the object. */
    public function description(): AssignmentScopeDescription;
}
