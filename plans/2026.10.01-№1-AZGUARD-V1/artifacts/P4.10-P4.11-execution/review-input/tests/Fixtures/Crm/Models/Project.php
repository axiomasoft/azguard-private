<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Project extends Model
{
    protected $table = 'projects';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
