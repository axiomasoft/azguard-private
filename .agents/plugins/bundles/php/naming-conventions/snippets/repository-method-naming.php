<?php

declare(strict_types=1);

/**
 * Repository refactoring: scattering findByX...OrFail → scopes + criterion.
 * Demonstrates the transition from «method-on-field» to naming by intent.
 */

// ════════════════════════════════════════════════════════════════════════════
// WAS: every new search method = new method, where-logic is duplicated
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
// NOW: predicates - in model scopes by intent; the repository compiles them
// ════════════════════════════════════════════════════════════════════════════

// app/Models/Document.php — scope-names express business meaning, not column
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

    // not-id key for route model binding: search by slug does Laravel himself
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

// app/Repositories/Document/DocumentReadRepository.php
final class DocumentReadRepository
{
    // ONE canonical lookup over visibility (see skill repositories)
    public function findByIdOrFail(int $id, ?User $user = null): Document
    {
        return $this->queryForUser($user)->active()->findOrFail($id);
    }

    // named query - about intent «author's drafts», and not findByAuthorIdAndStatus
    public function draftsOf(User $author): Collection
    {
        return $this->queryForUser($author)
            ->active()
            ->authoredBy($author)
            ->withStatus(DocumentStatus::Draft)
            ->get();
    }
}

// In the controller, search by slug — without any repository method at all:
//   public function show(Document $document) { ... }   // binding by getRouteKeyName()
// One-time search by field - standard Eloquent:
//   Document::active()->firstWhere('code', $code);
