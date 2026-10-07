<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Scopes\AssignmentScopePhase;

/**
 * What a change does to the grants and the dynamic permissions of a panel.
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
    case CreatePermission = 'create_permission';
    case UpdatePermission = 'update_permission';
    case DeletePermission = 'delete_permission';

    /** A new or repeated grant of a role or a permission. */
    public function isGrant(): bool
    {
        return $this === self::GrantRole || $this === self::GrantPermission;
    }

    public function isRevocation(): bool
    {
        return $this === self::RevokeRole || $this === self::RevokePermission;
    }

    /** A change of a dynamic permission itself, not of a grant. */
    public function isAction(): bool
    {
        return $this === self::CreatePermission || $this === self::UpdatePermission || $this === self::DeletePermission;
    }

    /** Whether the change proposes new expiry and fields that pass Assignment validation. */
    public function proposes(): bool
    {
        return $this->isGrant() || $this === self::UpdateGrant;
    }

    /** Whether the change carries fields a pipe may replace: a grant, an update or a dynamic permission to be stored. */
    public function proposesFields(): bool
    {
        return $this->proposes() || $this === self::CreatePermission || $this === self::UpdatePermission;
    }

    public function phase(): AssignmentScopePhase
    {
        return $this->isRevocation() || $this === self::DeletePermission ? AssignmentScopePhase::Revocation : AssignmentScopePhase::Assignment;
    }
}
