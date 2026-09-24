<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Repositories\Document;

use App\Enums\Document\DocumentStatus;
use App\Exceptions\DocumentAccessException;
use App\Models\Document\Document;
use App\Models\User;
use App\Services\Document\Access\DocumentVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side репозиторий: запросы, фильтры, пагинация, eager loading.
 * Никаких мутаций, никаких авторизационных решений — видимость делегируется
 * в DocumentVisibilityService.
 */
final class DocumentReadRepository
{
    public function __construct(
        private readonly DocumentVisibilityService $documentVisibilityService,
    ) {}

    /**
     * Единая точка входа read-side: каждая выборка проходит через сервис видимости.
     * Сигнатура явно сообщает потребителю, что результат отфильтрован по правам.
     *
     * @param  bool  $ignorePermissions  true — вернуть все записи (админские обзоры, счётчики «Все»)
     * @return Builder<Document>
     */
    public function queryForUser(?User $user = null, bool $ignorePermissions = false): Builder
    {
        return $this->documentVisibilityService->apply(
            query: Document::query()->latest(),
            user: $user,
            ignorePermissions: $ignorePermissions,
        );
    }

    /**
     * «Не найдено» и «нет доступа» намеренно неразличимы для потребителя:
     * запрос строится поверх queryForUser, недоступная запись просто не находится.
     *
     * @throws DocumentAccessException
     */
    public function findByIdOrFail(int $id, ?User $user = null): Document
    {
        $document = $this->queryForUser(user: $user)
            ->where(column: 'id', operator: '=', value: $id)
            ->first();

        if (! $document) {
            throw DocumentAccessException::forDocument(documentId: $id);
        }

        return $document;
    }

    /**
     * Пагинация списка: доменный scope withListRelations + точечный eager load
     * связей, нужных конкретно этому экрану.
     */
    public function paginate(?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryForUser(user: $user)
            ->withListRelations()
            ->with(['experts.user', 'responsibles.user'])
            ->paginate(perPage: $perPage);
    }

    /**
     * Репозиторий КОМПОНУЕТ доменные scopes модели, а не дублирует where-цепочки.
     *
     * @return Builder<Document>
     */
    public function getArchive(?User $user = null): Builder
    {
        return $this->queryForUser(user: $user)->withStatus(status: DocumentStatus::Archived);
    }

    /**
     * @return Builder<Document>
     */
    public function getByStatus(DocumentStatus $status, ?User $user = null): Builder
    {
        return $this->queryForUser(user: $user)->withStatus(status: $status);
    }

    /**
     * Поиск + scopes + пагинация в одной компонуемой цепочке.
     */
    public function paginateBySearch(User $user, ?string $query, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryForUser(user: $user)
            ->when(
                value: $query !== null && $query !== '',
                callback: fn (Builder $builder) => $builder->where(
                    column: 'title',
                    operator: 'ilike',
                    value: "%{$query}%",
                ),
            )
            ->withListRelations()
            ->paginate(perPage: $perPage)
            ->withQueryString();
    }
}
