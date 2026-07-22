<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Services\Document\Store;

use App\Dto\Document\Form\Form;
use App\Dto\Document\Form\Members;
use App\Dto\Document\Repository\MembersDelta;
use App\Enums\User\UserRole;
use App\Events\Document\MembersChanged;
use App\Models\Document\Document;
use App\Models\User;
use App\Repositories\Document\Media\AttachmentStoreRepository;
use App\Repositories\Document\DocumentStoreRepository;
use App\Repositories\Document\MemberStoreRepository;
use App\Services\Document\Access\MemberAccessService;
use App\Services\Document\UpdatedBroadcaster;

/**
 * СЕРВИС-ОРКЕСТРАТОР: координирует три зависимости в один сценарий сохранения.
 * Сам не открывает транзакцию — атомарность обеспечивает вызывающий Action.
 *
 * Service инжектит Repository и другие Service — но не Actions и не Controller.
 */
final readonly class DocumentPersistenceService
{
    public function __construct(
        private DocumentStoreRepository $documentStoreRepository,
        private MemberSyncService $memberSyncService,
        private AttachmentStoreRepository $attachmentStoreRepository,
    ) {}

    /**
     * Сохранение документа из формы: строка документа, участники, вложения.
     */
    public function storeOrUpdate(Form $form, User $user, bool $syncMembers = true): Document
    {
        $document = $this->documentStoreRepository->persistFromForm(form: $form, user: $user);

        if ($syncMembers) {
            $this->memberSyncService->syncByForm(
                document: $document,
                user: $user,
                members: $form->members,
            );
        }

        $this->attachmentStoreRepository->syncFromForm(document: $document, form: $form);

        return $document;
    }
}

/**
 * СЕРВИС С ДЕЛЕГИРОВАНИЕМ: запись делегирует репозиторию, права — access-сервису,
 * а сам отвечает за оркестрацию ролей, доменное событие и broadcast.
 *
 * Event::dispatch / Model-события — инфраструктурные статики, их НЕ инжектят
 * (это не зависимости-коллабораторы). Broadcaster — обычный сервис, его инжектят.
 */
final readonly class MemberSyncService
{
    public function __construct(
        private MemberStoreRepository $memberStoreRepository,
        private MemberAccessService $memberAccessService,
        private UpdatedBroadcaster $documentUpdatedBroadcaster,
    ) {}

    public function syncByForm(Document $document, User $user, Members $members): void
    {
        // Какие роли разрешено редактировать — решает отдельный access-сервис.
        $editPermissions = $this->memberAccessService->resolveEditPermissions(document: $document, actor: $user);
        $roleSyncConfig = [
            [
                'enabled' => $editPermissions->owner,
                'role' => UserRole::Owner,
                'userIds' => $members->owner !== null ? [$members->owner->id] : [],
            ],
            [
                'enabled' => $editPermissions->reviewers,
                'role' => UserRole::Reviewer,
                'userIds' => array_map(static fn ($member): int => $member->id, $members->reviewers),
            ],
            [
                'enabled' => $editPermissions->approvers,
                'role' => UserRole::Approver,
                'userIds' => array_map(static fn ($member): int => $member->id, $members->approvers),
            ],
        ];

        $added = [];
        $removed = [];
        foreach ($roleSyncConfig as $config) {
            // Фактическую запись делегируем репозиторию, накапливаем дельту по всем ролям.
            $changes = $config['enabled']
                ? $this->memberStoreRepository->syncRoleMembers(
                    document: $document,
                    role: $config['role'],
                    userIds: $config['userIds'],
                )
                : new MembersDelta(added: [], removed: []);

            $added = [...$added, ...$changes->added];
            $removed = [...$removed, ...$changes->removed];
        }

        if ($added !== [] || $removed !== []) {
            // Доменное событие — через статический dispatch (инфраструктура, не зависимость).
            MembersChanged::dispatch(
                document: $document,
                actor: $user,
                addedMembers: $added,
                removedMembers: $removed,
            );

            // Broadcast — через инжектированный сервис: это коллаборатор с логикой.
            $this->documentUpdatedBroadcaster->queueByDocumentId(documentId: $document->id);
        }
    }

    /**
     * Синхронизация одной роли без broadcast (для внешних интеграционных сценариев).
     */
    public function syncByRoleWithoutBroadcast(Document $document, UserRole $role, array $userIds): MembersDelta
    {
        return $this->memberStoreRepository->syncRoleMembers(
            document: $document,
            role: $role,
            userIds: $userIds,
        );
    }
}
