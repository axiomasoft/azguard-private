<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Configuration\Config;
use AzGuard\Contracts\RolePermissionValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * DB-level permissions assigned to a role via the role_permissions table.
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission_key
 * @property string $panel_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RolePermission extends Model
{
    #[Override]
    public function getTable(): string
    {
        return Config::rolePermissionsTable();
    }

    protected $fillable = [
        'role_id',
        'permission_key',
        'panel_id',
    ];

    /**
     * Opt-in guard against un-linted permission keys. Off by default (lenient),
     * so existing behaviour is unchanged; when enabled, the swappable
     * {@see RolePermissionValidator} vets the key on every save.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saving(static function (self $rolePermission): void {
            if (! Config::validateRolePermissionsEnabled()) {
                return;
            }

            app(RolePermissionValidator::class)->validate(
                (string) $rolePermission->permission_key,
                (string) $rolePermission->panel_id,
            );
        });
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Config::roleModel(), 'role_id');
    }
}
