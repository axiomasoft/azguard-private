<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Services\Document\Access;

use App\Enums\Document\DocumentStatus;
use App\Enums\Document\Permissions\CommonPermission;
use App\Enums\User\UserRole;
use App\Models\Document\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The only place where visibility rules turn into SQL-restrictions.
 * Injected into read-repository; the repository of authorization solutions itself
 * does not accept. Declarative tables «role → statuses/attitude» instead if-cascades.
 */
final class DocumentVisibilityService
{
    /**
     * @var array<string, array<int, DocumentStatus>>
     */
    private const ROLE_STATUS_VISIBILITY = [
        UserRole::Reviewer->value => [
            DocumentStatus::ReviewStarted,
            DocumentStatus::ReviewFinished,
        ],
        UserRole::Auditor->value => [
            DocumentStatus::AuditStarted,
            DocumentStatus::AuditFinished,
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_RELATION = [
        UserRole::Reviewer->value => 'reviewers',
        UserRole::Auditor->value => 'auditors',
    ];

    /**
     * Applies document visibility rules to the request.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function apply(Builder $query, ?User $user, bool $ignorePermissions = false): Builder
    {
        if ($ignorePermissions) {
            return $query;
        }

        if (! $user instanceof User) {
            throw new InvalidArgumentException('A user is required to select documents.');
        }

        if (Gate::forUser(user: $user)->allows(ability: CommonPermission::ViewAny->value)) {
            return $query;
        }

        foreach ([UserRole::Reviewer, UserRole::Auditor] as $role) {
            if (! $user->hasRole($role)) {
                continue;
            }

            return $this->applyRoleVisibility(
                query: $query,
                relation: self::ROLE_RELATION[$role->value],
                user: $user,
                statuses: self::ROLE_STATUS_VISIBILITY[$role->value],
            );
        }

        if ($user->hasMemberRole()) {
            return $this->applyMemberVisibility(query: $query, user: $user);
        }

        return $this->applyCreatorVisibility(query: $query, user: $user);
    }

    /**
     * Narrow Role Visibility: Only «theirs» documents and only in stage statuses.
     *
     * @param  array<int, DocumentStatus>  $statuses
     * @return Builder<Document>
     */
    private function applyRoleVisibility(Builder $query, string $relation, User $user, array $statuses): Builder
    {
        return $query
            ->whereHas(
                relation: $relation,
                callback: fn (Builder $builder) => $builder->withUserId(userId: $user->id),
            )
            ->withStatuses(statuses: $statuses);
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function applyMemberVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            relation: 'members',
            callback: fn (Builder $builder) => $builder->withUserId(userId: $user->id),
        );
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function applyCreatorVisibility(Builder $query, User $user): Builder
    {
        return $query->where(column: 'creator_id', operator: '=', value: $user->id);
    }
}
