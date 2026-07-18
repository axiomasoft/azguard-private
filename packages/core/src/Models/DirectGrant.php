<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property string $grantable_type
 * @property int $grantable_id
 * @property string $panel_id
 * @property string $permission_key
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<self> active()
 * @method static Builder<self> forPanel(string $panelId)
 */
class DirectGrant extends Model
{
    protected $fillable = [
        'grantable_type',
        'grantable_id',
        'panel_id',
        'permission_key',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Flush the grantable's cached permissions whenever a grant is written or
     * deleted — covers the Filament resource, raw model saves/deletes, and any
     * other model-event path. The grantable_id is the cache user id, so no model
     * load is needed. (GrantBuilder's bulk revoke fires GrantRevoked instead.)
     *
     * On update, also flush the ORIGINAL (panel_id, grantable) pair when either
     * changed (C-09): moving a grant from panel A to B, or reassigning it to a
     * different grantable, otherwise leaves panel A's stale cached permission
     * set alive until TTL — the new-value flush above never touches it.
     */
    #[Override]
    protected static function booted(): void
    {
        $flush = static function (self $grant): void {
            app(PermissionCache::class)->forgetForUser($grant->grantable_id, $grant->panel_id);
        };

        static::created($flush);

        static::updated(static function (self $grant) use ($flush): void {
            $originalPanelId = $grant->getOriginal('panel_id');
            $originalGrantableId = $grant->getOriginal('grantable_id');

            if ($originalPanelId !== $grant->panel_id || $originalGrantableId !== $grant->grantable_id) {
                app(PermissionCache::class)->forgetForUser($originalGrantableId, $originalPanelId);
            }

            $flush($grant);
        });

        static::deleted($flush);
    }

    #[Override]
    public function getTable(): string
    {
        return Config::directGrantsTable();
    }

    // ─── Relations ───────────────────────────────────────────────────────────

    /** @return MorphTo<Model, $this> */
    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── Scopes ────────────────────────────────────────────────────────────

    /**
     * Only non-expired grants: no expiry date OR expires_at > now().
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForPanel(Builder $query, string $panelId): void
    {
        $query->where('panel_id', $panelId);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isExpired();
    }
}
