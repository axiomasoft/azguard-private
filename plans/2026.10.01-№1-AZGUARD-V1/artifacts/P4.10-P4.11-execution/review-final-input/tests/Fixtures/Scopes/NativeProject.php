<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NativeProject extends Model
{
    protected $table = 'native_projects';

    public $timestamps = false;

    /** @return HasMany<NativeMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(NativeMember::class, 'project_id');
    }

    /** @param Builder<NativeProject> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
