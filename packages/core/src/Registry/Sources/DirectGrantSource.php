<?php

declare(strict_types=1);

namespace AzGuard\Registry\Sources;

use AzGuard\Configuration\Config;
use AzGuard\Models\DirectGrant;
use AzGuard\Registry\Contracts\GrantPriority;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Grant source from the az_guard_direct_grants table.
 *
 * Direct per-user permissions without a role: temporary access,
 * overrides, temporary privilege escalation.
 * Filters by expires_at: null = never expires, otherwise active only.
 * Gated by features.direct_grants; disabled -> empty PermissionSet, no query.
 * Model resolved via Config::directGrantModel() so a custom model is honoured.
 * Priority: 80.
 */
final class DirectGrantSource implements GrantSource
{
    #[Override]
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        if (! Config::directGrantsEnabled()) {
            return PermissionSet::empty();
        }

        /** @var class-string<DirectGrant> $model */
        $model = Config::directGrantModel();

        $keys = $model::query()
            ->where('grantable_type', $user->getMorphClass())
            ->where('grantable_id', $user->getAuthIdentifier())
            ->where('panel_id', $panelId)
            ->active()
            ->pluck('permission_key')
            ->all();

        return PermissionSet::fromRawKeys($keys);
    }

    #[Override]
    public function priority(): int
    {
        return GrantPriority::DirectGrant->value;
    }
}
