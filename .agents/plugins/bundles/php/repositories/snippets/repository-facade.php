<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Repositories\Document;

use App\Enums\Document\DocumentStatus;
use App\Exceptions\DocumentAccessException;
use App\Models\Document\Document;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Фасад-агрегатор read-методов: единая точка для контроллеров/компонентов,
 * когда read-методов много. Чистое делегирование — никакой логики.
 * Опционален: при 2-3 методах инжектируйте DocumentReadRepository напрямую.
 */
final class Repository
{
    public function __construct(
        private readonly DocumentReadRepository $documentReadRepository,
    ) {}

    /**
     * @param  bool  $ignorePermissions  true — все записи (админские обзоры)
     * @return Builder<Document>
     */
    public function queryForUser(?User $user = null, bool $ignorePermissions = false): Builder
    {
        return $this->documentReadRepository->queryForUser(
            user: $user,
            ignorePermissions: $ignorePermissions,
        );
    }

    /**
     * @throws DocumentAccessException
     */
    public function findByIdOrFail(int $id, ?User $user = null): Document
    {
        return $this->documentReadRepository->findByIdOrFail(id: $id, user: $user);
    }

    public function paginate(?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        return $this->documentReadRepository->paginate(user: $user, perPage: $perPage);
    }

    /**
     * @return Builder<Document>
     */
    public function getArchive(?User $user = null): Builder
    {
        return $this->documentReadRepository->getArchive(user: $user);
    }

    /**
     * @return Builder<Document>
     */
    public function getByStatus(DocumentStatus $status, ?User $user = null): Builder
    {
        return $this->documentReadRepository->getByStatus(status: $status, user: $user);
    }

    public function paginateBySearch(User $user, ?string $query, int $perPage = 20): LengthAwarePaginator
    {
        return $this->documentReadRepository->paginateBySearch(
            user: $user,
            query: $query,
            perPage: $perPage,
        );
    }
}
