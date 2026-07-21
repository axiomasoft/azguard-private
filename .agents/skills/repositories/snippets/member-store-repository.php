<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Repositories\Document;

use App\Dto\Document\Repository\MembersDelta;
use App\Enums\Document\MemberStatus;
use App\Enums\User\UserRole;
use App\Models\Document\Document;
use App\Models\Document\Member;

/**
 * Низкоуровневая запись участников документа (без событий и broadcast).
 * Кто и о чём уведомлять по дельте — решает вызывающий Action.
 */
final readonly class MemberStoreRepository
{
    /**
     * Синхронизирует участников одной роли и возвращает дельту изменений.
     * Delta-DTO вместо void: Action узнаёт, кого добавили/убрали,
     * не перечитывая базу и не сравнивая состояния сам.
     *
     * @param  array<int, int|string>  $userIds
     */
    public function syncRoleMembers(Document $document, UserRole $role, array $userIds): MembersDelta
    {
        $intIds = array_map(callback: fn ($id): int => (int) $id, array: $userIds);

        $existingIds = Member::query()
            ->forDocument(documentId: $document->id)
            ->withRole(role: $role)
            ->pluck(column: 'user_id')
            ->map(callback: fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($intIds as $index => $userId) {
            Member::query()->updateOrCreate(attributes: [
                'user_id' => $userId,
                'document_id' => $document->id,
                'role' => $role,
            ], values: [
                'priority' => $index + 1,
            ]);
        }

        $removed = Member::query()
            ->forDocument(documentId: $document->id)
            ->withRole(role: $role)
            ->whereNotIn(column: 'user_id', values: $intIds)
            ->get();

        $removedMembers = $removed->map(callback: fn (Member $member): array => [
            'role' => $member->role->value,
            'user_id' => $member->user_id,
        ])->all();

        $addedMembers = array_map(
            callback: fn (int $userId): array => [
                'role' => $role->value,
                'user_id' => $userId,
            ],
            array: array_values(array: array_diff($intIds, $existingIds)),
        );

        $removed->each->delete();

        return new MembersDelta(
            added: $addedMembers,
            removed: $removedMembers,
        );
    }

    /**
     * Точечная мутация статуса участника — тоже write-side, тоже без событий.
     */
    public function saveReviewDecision(Member $member, bool $decision, string $comment): void
    {
        $member->update(attributes: [
            'status' => $decision ? MemberStatus::Approved : MemberStatus::Rejected,
            'status_updated_at' => now(),
            'comment' => $comment,
        ]);
    }
}
