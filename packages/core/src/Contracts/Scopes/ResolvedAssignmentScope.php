<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Scopes;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A resolved assignment scope with its owner tenant and, for a local definition, the loaded record.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ResolvedAssignmentScope
{
    public function __construct(
        public AssignmentScopeRef $ref,
        public TenantRef $tenant,
        public ?Model $record = null,
    ) {}
}
