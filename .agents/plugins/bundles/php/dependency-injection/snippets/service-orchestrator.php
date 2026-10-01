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
 * SERVICE ORCHESTRATOR: Coordinates three dependencies into one persistence script.
 * Doesn't open a transaction itself - atomicity is provided by the caller Action.
 *
 * Service injectitis Repository and others Service — but not Actions and not Controller.
 */
final readonly class DocumentPersistenceService
{
    public function __construct(
        private DocumentStoreRepository $documentStoreRepository,
        private MemberSyncService $memberSyncService,
        private AttachmentStoreRepository $attachmentStoreRepository,
    ) {}

    /**
     * Saving a document from a form: document line, participants, attachments.
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
 * SERVICE WITH DELEGATION: the record delegates to the repository, rights - access-service,
 * and is responsible for role orchestration, domain event and broadcast.
 *
 * Event::dispatch / Model-events are infrastructure statics, they are NOT injected
 * (these are not collaborating dependencies). Broadcaster — regular service, it will be injected.
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
        // Which roles are allowed to edit is decided by the individual access-service.
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
            // We delegate the actual record to the repository, accumulate the delta for all roles.
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
            // Domain event - via static dispatch (infrastructure, not dependency).
            MembersChanged::dispatch(
                document: $document,
                actor: $user,
                addedMembers: $added,
                removedMembers: $removed,
            );

            // Broadcast — via an injected service: this is a collaborator with logic.
            $this->documentUpdatedBroadcaster->queueByDocumentId(documentId: $document->id);
        }
    }

    /**
     * Sync one role without broadcast (for external integration scripts).
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
