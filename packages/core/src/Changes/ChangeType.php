<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Scopes\AssignmentScopePhase;

/**
 * What a change does to the grants of a panel.
 *
 * @api
 */
enum ChangeType: string
{
    case GrantRole = 'grant_role';
    case RevokeRole = 'revoke_role';
    case GrantPermission = 'grant_permission';
    case RevokePermission = 'revoke_permission';
    case UpdateGrant = 'update_grant';

    /** A new or repeated grant of a role or a permission. */
    public function isGrant(): bool
    {
        return $this === self::GrantRole || $this === self::GrantPermission;
    }

    public function isRevocation(): bool
    {
        return $this === self::RevokeRole || $this === self::RevokePermission;
    }

    /** Whether the change proposes new expiry and fields that pass Assignment validation. */
    public function proposes(): bool
    {
        return $this->isGrant() || $this === self::UpdateGrant;
    }

    public function phase(): AssignmentScopePhase
    {
        return $this->isRevocation() ? AssignmentScopePhase::Revocation : AssignmentScopePhase::Assignment;
    }
}
