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
 * ПРОСТОЙ Action: одна зависимость, одна транзакция, Command DTO.
 *
 * Канон класса: final readonly + promoted properties в конструкторе.
 * Конструктор = зависимости (сервисы), параметры execute() = данные (DTO, флаги).
 */
final readonly class StoreAction
{
    public function __construct(
        private DocumentPersistenceService $persistence,
    ) {}

    /**
     * Создает или обновляет документ по форме и при необходимости синхронизирует состав участников.
     */
    public function execute(BaseStoreCommand $command, bool $syncMembers = true): Document
    {
        return DB::transaction(function () use ($command, $syncMembers): Document {
            // Создание/обновление и связанная синхронизация состава должны быть атомарными,
            // чтобы не оставить документ в частично сохраненном состоянии.
            return $this->persistence->storeOrUpdate(
                form: $command->form,
                user: $command->user,
                syncMembers: $syncMembers,
            );
        });
    }
}

/**
 * СРЕДНИЙ Action: две зависимости (StateMachine + репозиторий записи),
 * бизнес-валидация через ValidationException (НЕ abort()), ветвление сценария.
 */
final readonly class SummaryReplyAction
{
    public function __construct(
        private StateMachine $stateMachine,
        private DocumentStoreRepository $storeRepository,
    ) {}

    /**
     * Сохраняет сводный ответ и при отправке переводит документ на этап утверждения.
     *
     * @throws ValidationException
     */
    public function execute(SummaryReplyCommand $command): void
    {
        // Бизнес-правило проверяется ДО транзакции: при отправке на утверждение
        // обязателен хотя бы один утверждающий, иначе процесс зависнет.
        if ($command->send && $command->document->approvers()->count() === 0) {
            throw ValidationException::withMessages([
                'send' => 'Не задан ни один утверждающий',
            ]);
        }

        DB::transaction(function () use ($command): void {
            $previousReply = $command->document->summary_reply;

            if ($command->send) {
                // В режиме "send" одновременно фиксируем новую версию ответа,
                // пишем diff в историю и переводим документ в UnderApproval.
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

                // После перехода отдельное сохранение не нужно:
                // summary_reply уже записан через attributes transitionData.
                return;
            }

            // В черновом режиме обновляем только текст ответа
            // без смены статуса и без запуска цикла согласования.
            $this->storeRepository->saveSummaryReply(
                document: $command->document,
                user: $command->user,
                message: $command->message,
            );
        });
    }
}
