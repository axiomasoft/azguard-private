<?php

declare(strict_types=1);

namespace AzGuard\Context\Models;

use AzGuard\Registry\Resolver\PermissionCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * A single row of az_guard_context_roles: "user X in context (type, id) of
 * the {panel} panel has the {permission_key} permission".
 *
 * Written by ContextGrantBuilder (used by guard:context:grant/revoke);
 * read by ContextPermissionLayer::contextPermissions() via a plain query
 * (no repository seam — see ARCHITECT_REVIEW.md §6.5).
 *
 * @property int $id
 * @property string $model_type
 * @property int|string $model_id
 * @property string $context_type
 * @property string $context_id
 * @property string $panel_id
 * @property string $permission_key
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<self> forPanel(string $panelId)
 * @method static Builder<self> inContext(string $contextType, int|string $contextId)
 * @method static Builder<self> active()
 */
final class ContextRole extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'context_type',
        'context_id',
        'panel_id',
        'permission_key',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Flush the grantable's cached permissions whenever a context grant is
     * written, re-stamped (expires_at update) or deleted — mirrors
     * DirectGrant::booted() so the effective permission set never serves a
     * stale context grant from cache.
     */
    #[Override]
    protected static function booted(): void
    {
        $flush = static function (self $contextRole): void {
            app(PermissionCache::class)->forgetForUser($contextRole->model_id, $contextRole->panel_id);
        };

        self::created($flush);
        self::updated($flush);
        self::deleted($flush);
    }

    #[Override]
    public function getTable(): string
    {
        return (string) config('az-guard-context.table_names.context_roles', 'az_guard_context_roles');
    }

    // ─── Relations ───────────────────────────────────────────────────────────

    /** @return MorphTo<Model, $this> */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── Scopes ────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForPanel(Builder $query, string $panelId): void
    {
        $query->where('panel_id', $panelId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInContext(Builder $query, string $contextType, int|string $contextId): void
    {
        $query->where('context_type', $contextType)->where('context_id', $contextId);
    }

    /**
     * Only non-expired grants: no expiry date OR expires_at > now().
     * Mirrors DirectGrant::scopeActive().
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

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Whether this grant has an expiry date in the past.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
