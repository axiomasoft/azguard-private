<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Repositories\Document;

use App\Dto\Document\Form\Form;
use App\Dto\Document\Form\RelatedDocumentData;
use App\Enums\Document\DocumentStatus;
use App\Models\Document\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Write-side repository: writing document fields by DTO-command (Form).
 *
 * Contract:
 * - runs INSIDE the caller's transaction Action — does not open transactions itself;
 * - DOES NOT write history and DO NOT dispatch events - this is a responsibility Action/StateMachine;
 * - accepts DTO (Form), and not Request and not raw arrays.
 */
final readonly class DocumentStoreRepository
{
    /**
     * Creates or updates a document line from a form.
     */
    public function persistFromForm(Form $form, User $user): Document
    {
        $documentData = $form->toDb();

        if ($form->id !== null) {
            $document = Document::query()->findOrFail(id: $form->id);
            $document->update(attributes: $documentData);
        } else {
            $document = Document::query()->create(attributes: array_merge($documentData, [
                'creator_id' => $user->id,
                'owner_id' => $user->id,
                'status' => DocumentStatus::Created,
            ]));
        }

        // Common error: call here $document->recordHistory(...) or event(...).
        // Change history and domain events are written from Action/StateMachine,
        // who know the business context of the operation; the repository only knows strings.

        $this->syncRelatedDocuments(document: $document, relatedDocuments: $form->related_documents);

        return $document;
    }

    public function saveSummary(Document $document, string $summary): void
    {
        $document->update(attributes: [
            'summary' => $summary,
        ]);
    }

    /**
     * Sync private relationship «related documents»: pairs are normalized
     * (smaller id left), upsert new ones, removing dropped ones is idempotent.
     *
     * @param  array<int, RelatedDocumentData>  $relatedDocuments
     */
    private function syncRelatedDocuments(Document $document, array $relatedDocuments): void
    {
        $relatedDocumentIds = collect($relatedDocuments)
            ->map(callback: fn (RelatedDocumentData $item): int => $item->id)
            ->filter(callback: fn (int $relatedDocumentId): bool => $relatedDocumentId !== $document->id)
            ->unique()
            ->values()
            ->all();

        $normalizedPairs = collect($relatedDocumentIds)
            ->map(callback: function (int $relatedDocumentId) use ($document): array {
                return [
                    'document_id' => min($document->id, $relatedDocumentId),
                    'related_document_id' => max($document->id, $relatedDocumentId),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->unique(fn (array $pair): string => $pair['document_id'].'-'.$pair['related_document_id'])
            ->values();

        if ($normalizedPairs->isNotEmpty()) {
            DB::table(table: 'document_related')->upsert(
                values: $normalizedPairs->all(),
                uniqueBy: ['document_id', 'related_document_id'],
                update: ['updated_at'],
            );
        }

        $pairKeys = $normalizedPairs
            ->map(callback: fn (array $pair): string => $pair['document_id'].'-'.$pair['related_document_id'])
            ->all();

        DB::table(table: 'document_related')
            ->where(function ($query) use ($document): void {
                $query->where(column: 'document_id', operator: '=', value: $document->id)
                    ->orWhere(column: 'related_document_id', operator: '=', value: $document->id);
            })
            ->when(
                value: $pairKeys !== [],
                callback: fn ($query) => $query->whereNotIn(
                    column: DB::raw(value: "CONCAT(document_id, '-', related_document_id)"),
                    values: $pairKeys,
                ),
            )
            ->delete();
    }
}
