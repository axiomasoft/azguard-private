<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Actions\Document\Common;

use App\Actions\Document\Reply\WrittenReplyAction;
use App\Dto\Actions\Document\Base\BaseStoreCommand;
use App\Dto\Actions\Document\Reply\WrittenReplyCommand;
use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;

/**
 * COMPOSITE ACTION: multi-step script = Action, injecting atomic Actions.
 *
 * Instead «use-case Service» with a dozen methods - one execute(), one external
 * transaction, composition of atomic steps. Nested DB::transaction inside
 * children Actions are safe: Laravel reduces them to savepoint'am.
 *
 * Registration of a document through the form: saving, then either a written response,
 * or transfer to status "registered".
 */
final readonly class RegisterStoreAction
{
    public function __construct(
        private StoreAction $storeAction,
        private RegisteredAction $registeredAction,
        private WrittenReplyAction $writtenReplyAction,
    ) {}

    public function execute(BaseStoreCommand $command): Document
    {
        // One external transaction for the entire scenario: either the document is saved
        // And transferred to target status, or nothing happened.
        return DB::transaction(callback: function () use ($command): Document {
            $document = $this->storeAction->execute(command: $command);

            if ($document->written_reply) {
                // Quick completion thread: immediate written response.
                $this->writtenReplyAction->execute(
                    command: new WrittenReplyCommand(
                        document: $document,
                        user: $command->user,
                        result: true,
                    ),
                );

                return $document;
            }

            // Regular thread: commit registration (history + status + event).
            $this->registeredAction->execute(document: $document, user: $command->user);

            return $document;
        });
    }
}
