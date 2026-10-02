<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

/**
 * Who decides a permission: its policy alone, or assignments with an optional policy veto.
 *
 * The owner, membership and restriction boundary is never part of this answer; callers always AND it outside.
 */
enum PermissionAuthority: string
{
    case Policy = 'policy';
    case Grants = 'grants';

    /**
     * Whether a check reads grants and roles at all; a policy-only permission never touches assignments.
     */
    public function readsAssignments(): bool
    {
        return $this === self::Grants;
    }

    /**
     * Whether the permission may be assigned directly or through a role.
     */
    public function acceptsAssignments(): bool
    {
        return $this === self::Grants;
    }

    /**
     * Whether a qualified super-admin role counts as authority; under a policy it does not replace the policy.
     */
    public function superAdminIsAuthority(): bool
    {
        return $this === self::Grants;
    }

    /**
     * The authority verdict before the boundary.
     *
     * Policy: only `true` allows; `null` and `false` deny, and contributions are ignored.
     * Grants: a qualified contribution is required; a missing policy (`null`) or `true` passes, `false` vetoes.
     */
    public function allows(?bool $policy, bool $qualifiedContribution): bool
    {
        return match ($this) {
            self::Policy => $policy === true,
            self::Grants => $qualifiedContribution && $policy !== false,
        };
    }
}
