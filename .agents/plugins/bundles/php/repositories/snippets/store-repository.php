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
 * Write-side репозиторий: запись полей документа по DTO-команде (Form).
 *
 * Контракт:
 * - работает ВНУТРИ транзакции вызывающего Action — сам транзакций не открывает;
 * - НЕ пишет историю и НЕ диспатчит события — это ответственность Action/StateMachine;
 * - принимает DTO (Form), а не Request и не сырые массивы.
 */
final readonly class DocumentStoreRepository
{
    /**
     * Создаёт или обновляет строку документа по форме.
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

        // Типичная ошибка: вызвать здесь $document->recordHistory(...) или event(...).
        // История изменений и доменные события пишутся из Action/StateMachine,
        // которые знают бизнес-контекст операции; репозиторий знает только строки.

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
     * Sync приватного отношения «связанные документы»: пары нормализуются
     * (меньший id слева), upsert новых, удаление выпавших — идемпотентно.
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
