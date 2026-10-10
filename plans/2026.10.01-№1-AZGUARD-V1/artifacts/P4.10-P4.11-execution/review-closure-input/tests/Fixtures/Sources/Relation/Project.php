<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

final class Project extends Model
{
    public $timestamps = false;

    protected $table = 'relation_projects';

    protected $guarded = [];

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'relation_project_user', 'project_id', 'member_id')->withPivot(['role', 'is_lead']);
    }

    /** @return HasOne<Member, $this> */
    public function lead(): HasOne
    {
        return $this->hasOne(Member::class, 'lead_project_id');
    }

    /** @return MorphToMany<Member, $this> */
    public function morphMembers(): MorphToMany
    {
        return $this->morphToMany(Member::class, 'memberable', 'relation_memberships', 'memberable_id', 'member_id')->withPivot(['role', 'is_lead']);
    }

    public function malformed(): string
    {
        return 'not a relation';
    }
}
