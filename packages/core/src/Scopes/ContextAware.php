<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit resource-to-context mapping. Tenant resources also implement ProvidesAccessScope.
 *
 * @api
 *
 * @mixin Model
 */
trait ContextAware
{
    abstract public function azguardContextType(): string;

    /** A null relation means that this model itself is the context. */
    public function azguardContextRelation(): ?string
    {
        return null;
    }

    public function azguardContext(): ?Model
    {
        $relation = $this->azguardContextRelation();

        return $relation === null ? $this : $this->getRelationValue($relation);
    }

    /** Non-tenant shortcut, exposed by models implementing ProvidesAssignmentScope. */
    public function azguardAssignmentScope(): ?AssignmentScopeRef
    {
        $record = $this->azguardContext();

        return $record === null ? null : AssignmentScopeRef::of($this->azguardContextType(), $record->getKey());
    }
}
