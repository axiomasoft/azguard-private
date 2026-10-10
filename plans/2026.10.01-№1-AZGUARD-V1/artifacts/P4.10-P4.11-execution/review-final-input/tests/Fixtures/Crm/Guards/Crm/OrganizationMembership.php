<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm;

use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Support\Facades\DB;

final class OrganizationMembership implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef|AssignmentScopeRef $tenant): bool
    {
        return $subject->type() === 'crm.user' && $tenant->type() === 'crm.organization'
            && DB::table('organization_user')->where('organization_id', $tenant->id())->where('user_id', $subject->id())->exists();
    }
}
