<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Visibility;

use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Scopes\ContextAware;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VisibilityProject extends Model implements ProvidesAccessScope, ProvidesAssignmentScope
{
    use ContextAware;

    protected $table = 'visibility_projects';

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'project';
    }

    public function azguardScope(): AccessScope
    {
        return AccessScope::in(VisibilityWorld::tenant((string) $this->getAttribute('org')), $this->azguardAssignmentScope());
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'visibility_members', 'project_id', 'user_id');
    }
}
