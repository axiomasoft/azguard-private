<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An assignment scope backed by an Eloquent query.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface QueryableAssignmentScopeDefinition extends AssignmentScopeDefinition
{
    /**
     * A fresh structural query, before common and role filters.
     *
     * @return Builder<Model>
     */
    public function query(): Builder;

    /**
     * The owner tenant of an already loaded record.
     */
    public function tenantOf(Model $record): TenantRef;
}
