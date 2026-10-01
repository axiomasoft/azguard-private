<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Models\Document;

use App\Enums\Document\DocumentStatus;
use App\Enums\User\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Repeatable predicates live in the model as scopes with DOMAIN names.
 * The repository compiles them - where-chains are not duplicated across the codebase.
 */
final class Document extends Model
{
    /** All participants, sorted by priority. */
    public function members(): HasMany
    {
        return $this->hasMany(related: Member::class)
            ->orderBy(column: 'priority');
    }

    /** Members with role «Expert» — named relation slice. */
    public function experts(): HasMany
    {
        return $this->members()->where(column: 'role', operator: '=', value: UserRole::Expert);
    }

    /** Members with role «Responsible». */
    public function responsibles(): HasMany
    {
        return $this->members()->where(column: 'role', operator: '=', value: UserRole::Responsible);
    }

    /** Scope to load all the links needed to display the document list. */
    public function scopeWithListRelations(Builder $query): Builder
    {
        return $query
            ->with(['creator', 'members.user'])
            ->withExists([
                'history as has_approved_history' => fn (Builder $builder): Builder => $builder
                    ->where(column: 'status_after', operator: '=', value: DocumentStatus::Approved),
            ]);
    }

    public function scopeWithStatus(Builder $query, DocumentStatus $status): Builder
    {
        return $query->where(column: 'status', operator: '=', value: $status);
    }

    /**
     * @param  array<int, DocumentStatus|string>  $statuses
     */
    public function scopeWithStatuses(Builder $query, array $statuses): Builder
    {
        return $query->whereIn(column: 'status', values: $statuses);
    }

    /**
     * Simple domain predicate «user documents» (creator or contributor).
     * This is NOT authorization: role visibility is in VisibilityService,
     * scope just gives a reusable building block for it.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $builder) use ($user): void {
            $builder->where(column: 'creator_id', operator: '=', value: $user->id)
                ->orWhereHas(
                    relation: 'members',
                    callback: fn (Builder $members) => $members->where(
                        column: 'user_id',
                        operator: '=',
                        value: $user->id,
                    ),
                );
        });
    }
}
