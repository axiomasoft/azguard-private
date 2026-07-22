<?php

declare(strict_types=1);

/**
 * Рефакторинг репозитория: россыпь findByX...OrFail → scopes + критерий.
 * Демонстрирует переход от «метод-на-поле» к именованию по намерению.
 */

// ════════════════════════════════════════════════════════════════════════════
// БЫЛО: каждый новый способ поиска = новый метод, where-логика дублируется
// ════════════════════════════════════════════════════════════════════════════
final class DocumentRepositoryBefore
{
    public function findByCodeOrFail(string $code): Document
    {
        return Document::where('code', $code)->where('archived', false)->firstOrFail();
    }

    public function findBySlugOrFail(string $slug): Document
    {
        return Document::where('slug', $slug)->where('archived', false)->firstOrFail();
    }

    public function findActiveByAuthorAndStatus(int $authorId, string $status): Collection
    {
        return Document::where('author_id', $authorId)
            ->where('status', $status)
            ->where('archived', false)
            ->get();
    }
}

// ════════════════════════════════════════════════════════════════════════════
// СТАЛО: предикаты — в model scopes по намерению; репозиторий их компонует
// ════════════════════════════════════════════════════════════════════════════

// app/Models/Document.php — scope-имена выражают бизнес-смысл, не колонку
final class Document extends Model
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    public function scopeAuthoredBy(Builder $query, User $author): Builder
    {
        return $query->where('author_id', $author->id);
    }

    public function scopeWithStatus(Builder $query, DocumentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    // не-id ключ для route model binding: поиск по slug делает Laravel сам
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

// app/Repositories/Document/DocumentReadRepository.php
final class DocumentReadRepository
{
    // ОДИН канонический lookup поверх видимости (см. скилл repositories)
    public function findByIdOrFail(int $id, ?User $user = null): Document
    {
        return $this->queryForUser($user)->active()->findOrFail($id);
    }

    // именованный запрос — про намерение «черновики автора», а не findByAuthorIdAndStatus
    public function draftsOf(User $author): Collection
    {
        return $this->queryForUser($author)
            ->active()
            ->authoredBy($author)
            ->withStatus(DocumentStatus::Draft)
            ->get();
    }
}

// В контроллере поиск по slug — вообще без метода репозитория:
//   public function show(Document $document) { ... }   // binding по getRouteKeyName()
// Разовый поиск по полю — штатный Eloquent:
//   Document::active()->firstWhere('code', $code);
