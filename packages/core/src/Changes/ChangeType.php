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
    case TouchPanel = 'touch_panel';
    case MigrateRoleGrant = 'migrate_role_grant';

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

    /** A deliberate raise of the panel state version: it names no subject, key or grant and changes no stored row. */
    public function isTouch(): bool
    {
        return $this === self::TouchPanel;
    }

    /**
     * A stored role grant carried from a former key of a code role to its current key by an explicit migration: the
     * historical row keeps its scope, subject, origin, expiry and fields, and is not a new assignment.
     */
    public function isMigration(): bool
    {
        return $this === self::MigrateRoleGrant;
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
        return match (true) {
            $this === self::TouchPanel, $this === self::MigrateRoleGrant => AssignmentScopePhase::Inspection,
            $this->isRevocation(), $this === self::DeletePermission => AssignmentScopePhase::Revocation,
            default => AssignmentScopePhase::Assignment,
        };
    }
}
