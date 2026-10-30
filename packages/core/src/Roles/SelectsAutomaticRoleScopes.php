<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Identity\AccessScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional exact selection for a GrantedAutomatically role. Return every scope in which appliesTo() would return
 * true for this subject and the selected tenant (including permitted global-tenant scopes). The result must be
 * complete for the entire query, not a page, sample or partial traversal. Return null when exact enumeration is not
 * possible; an empty list asserts that no such scope exists. The selection is raw authority, not an allow decision.
 * A null context type means no resource mapping; the string 'global' denotes a concrete type with that name.
 * Implementations must preserve scalar semantics throughout the operation, and must not depend on a host row.
 */
#[Spi(kind: SpiKind::Implement, since: '2.0')]
interface SelectsAutomaticRoleScopes
{
    /** @return list<AccessScope>|null */
    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array;
}
