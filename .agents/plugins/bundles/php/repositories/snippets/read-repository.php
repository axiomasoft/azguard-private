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
 * Read-side repository: queries, filters, pagination, eager loading.
 * No mutations, no authorization decisions - visibility is delegated
 * in DocumentVisibilityService.
 */
final class DocumentReadRepository
{
    public function __construct(
        private readonly DocumentVisibilityService $documentVisibilityService,
    ) {}

    /**
     * Single point of entry read-side: each fetch goes through the visibility service.
     * The signature explicitly tells the consumer that the result is filtered by rights.
     *
     * @param  bool  $ignorePermissions  true — return all records (admin reviews, counters «All»)
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
     * «Not found» and «no access» are intentionally indistinguishable to the consumer:
     * request is built on top queryForUser, the unavailable record simply cannot be found.
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
     * List pagination: domain scope withListRelations + spot eager load
     * connections needed specifically for this screen.
     */
    public function paginate(?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryForUser(user: $user)
            ->withListRelations()
            ->with(['experts.user', 'responsibles.user'])
            ->paginate(perPage: $perPage);
    }

    /**
     * Repository COMPONENTS domain scopes model, not duplicate where-chains.
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
     * Search + scopes + pagination in one composable chain.
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
