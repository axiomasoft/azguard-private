<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Configuration\Config;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\SubjectIdentity;
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
     * other model-event path. Typed subject identity (morph type + id) drives
     * cache invalidation — no model load is needed. (GrantBuilder's bulk revoke fires GrantRevoked instead.)
     *
     * On update, also flush the ORIGINAL (panel_id, grantable_type, grantable_id) tuple when any
     * changed (C-09): moving a grant from panel A to B, reassigning it to a
     * different grantable, or changing morph type, otherwise leaves the old
     * cached permission set alive until TTL — the new-value flush above never touches it.
     *
     * Known gap (P1.4 review): a mass update/delete through the query builder
     * (DirectGrant::query()->update(...)) fires NO model events, so neither
     * the old nor the new key is flushed — inherent to every model-event hook.
     * Use guard:cache-reset (or per-model writes) after bulk mutations.
     */
    #[Override]
    protected static function booted(): void
    {
        $flush = static function (self $grant): void {
            app(PermissionCache::class)->forgetForUser(
                SubjectIdentity::fromPersisted($grant->grantable_type, $grant->grantable_id),
                $grant->panel_id,
            );
        };

        static::created($flush);

        static::updated(static function (self $grant) use ($flush): void {
            $originalPanelId = $grant->getOriginal('panel_id');
            $originalGrantableType = $grant->getOriginal('grantable_type');
            $originalGrantableId = $grant->getOriginal('grantable_id');

            if ($originalPanelId !== $grant->panel_id
                || $originalGrantableType !== $grant->grantable_type
                || $originalGrantableId !== $grant->grantable_id) {
                app(PermissionCache::class)->forgetForUser(
                    SubjectIdentity::fromPersisted((string) $originalGrantableType, $originalGrantableId),
                    (string) $originalPanelId,
                );
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
