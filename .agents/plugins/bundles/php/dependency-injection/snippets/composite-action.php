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
 * COMPOSITE ACTION: многошаговый сценарий = Action, инжектирующий атомарные Actions.
 *
 * Вместо «use-case Service» с десятком методов — один execute(), одна внешняя
 * транзакция, композиция атомарных шагов. Вложенные DB::transaction внутри
 * дочерних Actions безопасны: Laravel сводит их к savepoint'ам.
 *
 * Регистрация документа через форму: сохранение, затем либо письменный ответ,
 * либо перевод в статус "registered".
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
        // Одна внешняя транзакция на весь сценарий: либо документ сохранен
        // И переведен в целевой статус, либо ничего не произошло.
        return DB::transaction(callback: function () use ($command): Document {
            $document = $this->storeAction->execute(command: $command);

            if ($document->written_reply) {
                // Ветка быстрого завершения: сразу письменный ответ.
                $this->writtenReplyAction->execute(
                    command: new WrittenReplyCommand(
                        document: $document,
                        user: $command->user,
                        result: true,
                    ),
                );

                return $document;
            }

            // Обычная ветка: фиксация регистрации (история + статус + событие).
            $this->registeredAction->execute(document: $document, user: $command->user);

            return $document;
        });
    }
}
