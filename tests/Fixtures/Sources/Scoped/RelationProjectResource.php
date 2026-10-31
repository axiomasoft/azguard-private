<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Contracts\Scopes\MapsAssignmentScope;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Scopes\ContextAware;
use AzGuard\Tests\Fixtures\Sources\Relation\Project;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mapped resource over the relation fixture's finite project universe. */
final class RelationProjectResource extends Model implements MapsAssignmentScope, ProvidesAccessScope, ProvidesAssignmentScope
{
    use ContextAware;

    protected $table = 'relation_projects';

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'project';
    }

    public function azguardContextRelation(): ?string
    {
        return 'project';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'id');
    }

    public function azguardScope(): AccessScope
    {
        return RelationWorld::scope((int) $this->getKey(), (string) $this->getAttribute('tenant_id'));
    }
}
