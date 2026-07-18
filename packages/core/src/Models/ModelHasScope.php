<?php

declare(strict_types=1);

namespace AzGuard\Models;

use AzGuard\Configuration\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Entity-scoped role assignment.
 *
 * Represents a role granted to a model (e.g. User) scoped to a specific entity
 * (e.g. Project, Team). The scope_class defines optional query-scope behaviour.
 * A null scope_class indicates a logic-less role with no query filtering.
 *
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property string|null $scope_entity_type
 * @property int|null $scope_entity_id
 * @property string|null $scope_class
 * @property int|null $role_id
 * @property string|null $panel_id
 */
class ModelHasScope extends Model
{
    /**
     * scope_class is deliberately NOT fillable (C-11): it wires the scoped
     * role to a query-scope class (Concerns\HasScopedRoles::apply()) and must
     * only be set through a trusted internal path — assignScopedRole() sets
     * it via a direct property assignment (bypasses fillable, unlike
     * fill()/create()/firstOrCreate()), never from mass-assigned request input.
     */
    protected $fillable = [
        'model_id',
        'model_type',
        'scope_entity_id',
        'scope_entity_type',
        'role_id',
        'panel_id',
    ];

    /** @return MorphTo<Model, $this> */
    public function scopeEntity(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Config::roleModel());
    }
}
