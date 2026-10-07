<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Identity;

/**
 * Where an assignment applies: a tenant and an assignment scope inside it.
 *
 * The global scope inside a tenant means tenant-wide; it never equals the global tenant.
 */
final readonly class AccessScope
{
    private function __construct(
        public TenantRef $tenant,
        public AssignmentScopeRef $context,
    ) {}

    public static function in(TenantRef $tenant, ?AssignmentScopeRef $context = null): self
    {
        return new self($tenant, $context ?? AssignmentScopeRef::global());
    }

    public function equals(self $other): bool
    {
        return $this->tenant->equals($other->tenant) && $this->context->equals($other->context);
    }
}
