<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\ChangeResult;
use AzGuard\Facades\AzGuard;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Example host policy, deliberately limited to analyst assignments in one project. */
final class AuthorizedRoleAssignments
{
    /** $actorId must come from the authenticated session or a trusted job envelope, never request data. */
    public function change(?int $actorId, int $targetId, int $tenantId, int $projectId, string $role, bool $grant): ChangeResult
    {
        $actor = $actorId === null ? null : User::query()->find($actorId);
        $target = User::query()->find($targetId);
        $tenant = Organization::query()->find($tenantId);
        $project = Project::query()->whereKey($projectId)->where('organization_id', $tenantId)->where('is_active', true)->first();

        if ($actor === null || $target === null || $tenant === null || $project === null || $actor->is($target)
            || $role !== 'analyst' || ! $this->member($actor, $tenantId) || ! $this->member($target, $tenantId)
            || ! AzGuard::panel('crm')->inTenant($tenant)->for($actor)->hasRole('tenant-admin', on: $project)) {
            throw new AuthorizationException('Assignment denied.');
        }

        // actingAs uses ActingActor's scoped frame and restores it even if the write throws.
        return AzGuard::actingAs($actor, static function () use ($tenant, $target, $project, $role, $grant): ChangeResult {
            $access = AzGuard::panel('crm')->inTenant($tenant)->for($target);

            return $grant ? $access->grantRole($role, on: $project) : $access->revokeRole($role, on: $project);
        });
    }

    private function member(User $user, int $tenantId): bool
    {
        return $user->getConnection()->table('organization_user')->where('organization_id', $tenantId)
            ->where('user_id', $user->getKey())->exists();
    }
}
