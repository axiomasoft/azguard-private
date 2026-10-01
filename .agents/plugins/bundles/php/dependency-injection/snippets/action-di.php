<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Actions\Document\Common;

use App\Dto\Actions\Document\Base\BaseStoreCommand;
use App\Dto\Actions\Document\SummaryReplyCommand;
use App\Dto\Document\Workflow\TransitionData;
use App\Enums\Document\DocumentStatus;
use App\Enums\Document\EventCode;
use App\Models\Document\Document;
use App\Repositories\Document\DocumentStoreRepository;
use App\Services\Document\StateMachine;
use App\Services\Document\Store\DocumentPersistenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SIMPLE Action: one dependency, one transaction, Command DTO.
 *
 * Class Canon: final readonly + promoted properties in the constructor.
 * Constructor = dependencies (services), parameters execute() = data (DTO, flags).
 */
final readonly class StoreAction
{
    public function __construct(
        private DocumentPersistenceService $persistence,
    ) {}

    /**
     * Creates or updates a document based on a form and, if necessary, synchronizes the participants.
     */
    public function execute(BaseStoreCommand $command, bool $syncMembers = true): Document
    {
        return DB::transaction(function () use ($command, $syncMembers): Document {
            // Creation/update and associated composition synchronization must be atomic,
            // to avoid leaving the document in a partially saved state.
            return $this->persistence->storeOrUpdate(
                form: $command->form,
                user: $command->user,
                syncMembers: $syncMembers,
            );
        });
    }
}

/**
 * MEDIUM Action: two dependencies (StateMachine + entry repository),
 * business validation via ValidationException (NOT abort()), script branch.
 */
final readonly class SummaryReplyAction
{
    public function __construct(
        private StateMachine $stateMachine,
        private DocumentStoreRepository $storeRepository,
    ) {}

    /**
     * Saves the summary response and moves the document to the approval stage when submitted.
     *
     * @throws ValidationException
     */
    public function execute(SummaryReplyCommand $command): void
    {
        // The business rule is checked BEFORE the transaction: when submitted for approval
        // at least one approver is required, otherwise the process will hang.
        if ($command->send && $command->document->approvers()->count() === 0) {
            throw ValidationException::withMessages([
                'send' => 'No approvers specified',
            ]);
        }

        DB::transaction(function () use ($command): void {
            $previousReply = $command->document->summary_reply;

            if ($command->send) {
                // In mode "send" simultaneously fix the new version of the answer,
                // write diff into history and transfer the document to UnderApproval.
                $this->stateMachine->transition(
                    document: $command->document,
                    user: $command->user,
                    to: DocumentStatus::UnderApproval,
                    eventCode: EventCode::UnderApproval,
                    transitionData: TransitionData::make(
                        history: [
                            'summary_reply' => $command->message,
                            'old_summary_reply' => $previousReply,
                        ],
                        attributes: [
                            'summary_reply' => $command->message,
                        ],
                    ),
                );

                // After the transition, a separate save is not necessary:
                // summary_reply has already been recorded via attributes transitionData.
                return;
            }

            // In draft mode, we update only the response text
            // without changing the status and without starting the approval cycle.
            $this->storeRepository->saveSummaryReply(
                document: $command->document,
                user: $command->user,
                message: $command->message,
            );
        });
    }
}
