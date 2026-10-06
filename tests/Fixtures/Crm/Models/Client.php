<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Client extends Model implements ProvidesAccessScope
{
    protected $table = 'clients';

    protected $guarded = [];

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'crm.project';
    }

    public function azguardContextRelation(): string
    {
        return 'project';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function azguardScope(): AccessScope
    {
        return AccessScope::in(TenantRef::of('crm.organization', (string) $this->getAttribute('organization_id')),
            AssignmentScopeRef::of('crm.project', (string) $this->getAttribute('project_id')));
    }
}
