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
 * Повторяемые предикаты живут в модели как scopes с ДОМЕННЫМИ именами.
 * Репозиторий их компонует — where-цепочки не дублируются по кодовой базе.
 */
final class Document extends Model
{
    /** Все участники, отсортированные по приоритету. */
    public function members(): HasMany
    {
        return $this->hasMany(related: Member::class)
            ->orderBy(column: 'priority');
    }

    /** Участники с ролью «Эксперт» — именованный срез отношения. */
    public function experts(): HasMany
    {
        return $this->members()->where(column: 'role', operator: '=', value: UserRole::Expert);
    }

    /** Участники с ролью «Ответственный». */
    public function responsibles(): HasMany
    {
        return $this->members()->where(column: 'role', operator: '=', value: UserRole::Responsible);
    }

    /** Scope для загрузки всех связей, необходимых для отображения списка документов. */
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
     * Простой доменный предикат «документы пользователя» (создатель или участник).
     * Это НЕ авторизация: ролевая видимость — в VisibilityService,
     * scope лишь даёт переиспользуемый строительный блок для него.
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
