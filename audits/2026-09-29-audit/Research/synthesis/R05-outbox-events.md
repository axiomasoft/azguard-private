# R05 — События, afterCommit и outbox

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Laravel package grants/events: model events and domain events inside transactions, afterCommit / ShouldDispatchAfterCommit, queued listener rollback, worker crash after DB commit. Which cases require a transactional outbox and which do not when DB revision already ensures cache correctness? Design auditable event envelopes without requiring event sourcing or broker. Include nested transactions and failure semantics.

## Ответ Perplexity

afterCommit решает проблему порядка относительно транзакции: слушатель не увидит незакоммиченные данные и не запустится после rollback. Но это не гарантирует, что уведомление вообще переживёт падение процесса после commit. Outbox нужен не «для событий вообще», а когда потеря события недопустима и само событие должно быть устойчиво связано с изменением в БД. При уже работающем DB revision fence outbox обычно не нужен для корректности permission-кеша; он нужен для надёжной доставки внешним потребителям и обязательного аудита.

## Документированные факты

В Laravel 13 событие, реализующее ShouldDispatchAfterCommit, откладывается до commit активной транзакции; при rollback оно отбрасывается, а без открытой транзакции отправляется сразу. Для queued listeners можно использовать ShouldQueueAfterCommit; документация также предупреждает, что без этой задержки worker может обработать задание до commit и не найти созданные или обновлённые записи. Это гарантии поведения Laravel, а не долговечности отложенного вызова при аварии процесса. (документация проверена 2026-09-29)
Очереди Laravel работают с разными backends, в том числе с базой, Redis и SQS; момент отправки после commit настраивается отдельно. Следовательно, успешно зарегистрированный после commit callback и запись в независимую очередь — не одно и то же, что атомарная запись события в той же транзакции, что и grants. (документация проверена 2026-09-29)
AWS описывает outbox как способ устранить dual-write: изменение предметных данных и строка события записываются в одной транзакции; отдельный relay публикует событие. При сбоях доставки возможны повторы, поэтому потребитель должен быть идемпотентным. AWS Prescriptive Guidance, Transactional outbox pattern (актуальная страница; проверена 2026-09-29)

## Архитектурное применение

Модельные события Eloquent (created, updated, deleted, pivot-операции) — прежде всего локальные lifecycle hooks. Не следует считать их полным журналом grants: часть изменений проходит через bulk/raw SQL или иные пути, а callback после commit может потеряться при crash. Для синхронной логики инвариантов revision fence важнее события. Вызов ShouldDispatchAfterCommit сам по себе не устраняет эти ограничения.
Доменные события внутри транзакции уместны как семантические факты («GrantRevoked», «RoleAssigned»), но синхронный listener, который выполняет только локальную работу, может работать в этой же транзакции, если допустимы её побочные эффекты и откат. Для listeners, читающих уже сохранённое состояние, ставящих задачу в очередь или трогающих внешнюю систему, используйте after-commit поведение.
Outbox не требуется для инвалидирования permission-кеша, если DB revision атомарно увеличивается вместе с изменением, а проверка читает актуальную ревизию и cache key/envelope привязан к ней. Тогда пропуск уведомления означает максимум пропуск побочной реакции; правильность решения об авторизации не должна от него зависеть. Это inference из заданных предпосылок, а не подтверждение реализации приватного пакета.
Outbox требуется, если grant-change обязан гарантированно вызвать внешнюю реакцию: webhook, интеграционный broker, репликацию в другой сервис, обязательный audit trail или downstream-проекцию, где потеря события недопустима. Обычный afterCommit закрывает rollback/раннее чтение, но не окно «БД закоммичена — процесс умер до постановки job».

## Конверт и транзакции

Для аудируемого, но не event-sourced контракта предложил бы неизменяемый envelope: event_id (UUID), event_type, schema_version, occurred_at, tenant_id, aggregate_type/id, db_revision, context_discriminator, actor_id/correlation_id, changes (минимальные grant identifiers и операция), payload и при необходимости causation_id. Хранить envelope в outbox только если требуется durable delivery/audit; не превращать его в источник восстановления состояния. Удаление/сокрытие персональных данных и retention — отдельная политика.
При вложенных транзакциях событие должно считаться committed только после внешнего commit. Laravel описывает after-commit как ожидание завершения активных транзакций; проверьте поведение используемой версии, connection и savepoints, особенно при нескольких соединениях. Если вложенный блок откатился, его событие не должно пережить rollback; поэтому надёжнее писать outbox-строку в той же DB-транзакции, а не накапливать независимый callback.
Семантика outbox: rollback удаляет и grant-изменение, и envelope; crash после commit оставляет envelope для relay; crash после публикации, но до отметки доставленным, может дать дубль — потребителям нужна дедупликация по event_id. Для нескольких изменений в одной транзакции определите, публикуете ли вы каждое действие или один агрегированный факт с итоговой ревизией.

## Неизвестное

Не проверялись частные исходники, конкретное устройство revision fence, модельные write paths, DB connection topology, Laravel minor-версии и требования к сохранности аудита. Поэтому остаются вопросы: где именно revision инкрементируется, входят ли grants и discriminator в одну транзакцию, и нужен ли аудит как доказательная запись либо только диагностический лог.

Sources:
1. [Laravel 13.x Events](https://laravel.com/docs/13.x/events)
2. [Laravel 13.x Queues](https://laravel.com/docs/13.x/queues)
3. [AWS Prescriptive Guidance, Transactional outbox pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)
