<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Model;

/**
 * A resolved assignment scope with its owner tenant and, for a local definition, the loaded record.
 *
 * @spi
 */
final readonly class ResolvedAssignmentScope
{
    public function __construct(
        public AssignmentScopeRef $ref,
        public TenantRef $tenant,
        public ?Model $record = null,
    ) {}
}
