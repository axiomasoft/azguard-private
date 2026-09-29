# R03 — Записи Eloquent и административный API

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Laravel authorization package persistence design: public customizable Eloquent models and Filament resources versus application services/repositories. Mass updates, delete, pivot attach/detach/sync, upsert, saveQuietly, raw SQL, model event bypass; which writes need transaction revision fencing? Can repository interface alone prevent bypass? Propose a supported mutation contract without pretending Eloquent can be hidden from Filament.

## Ответ Perplexity

Короткий вывод: оставьте кастомизируемые Eloquent-модели и Filament Resources как публичный интеграционный интерфейс, но не делайте модельные события единственным механизмом поддержания revision fence. Любая запись, способная изменить фактические права или их интерпретацию через PermissionLayer и context discriminator, должна проходить через документированный mutation contract, который атомарно меняет данные и revision. Репозиторный интерфейс сам по себе обход не предотвращает.

## Документированные факты

Laravel 13 прямо указывает, что массовый update через Eloquent не загружает модели и не вызывает saving, saved, updating, updated. Аналогично, массовое удаление запросом не обрабатывает каждую модель; upsert — атомарная операция на уровне БД, но документация не описывает её как последовательность обычных model saves. saveQuietly() намеренно сохраняет модель без событий. Следовательно, observer/event hook нельзя считать полным перехватчиком всех способов записи. Документация Laravel 13, Eloquent, версия ветки 13.x; доступ проверен 2026-09-29
Для many-to-many Laravel предоставляет attach, detach, sync и updateExistingPivot как операции над промежуточной таблицей. Их семантика — изменение связей, а не обязательное изменение атрибутов моделей; поэтому события родительской модели не являются надёжной общей точкой наблюдения за изменением pivot. Документация Laravel 12, Relationships, версия ветки 12.x; доступ проверен 2026-09-29
Filament Resources по назначению работают с Eloquent-моделями и CRUD-интерфейсом, а Filament поддерживает bulk actions. Значит, обещать, что ресурсами можно пользоваться, но запретить приложению прямую работу с публичной моделью, было бы несогласованным контрактом. Filament 5.x, Resources overview, версия ветки 5.x; доступ проверен 2026-09-29

## Архитектурный вывод

Revision fence требуется для любого изменения, меняющего результат авторизации, включая создание, изменение, мягкое/жёсткое удаление и восстановление permission/role/assignment, а также изменения pivot, атрибутов pivot и иных таблиц, участвующих в разрешении прав или его контексте. Fence и бизнес-запись должны фиксироваться в одной транзакции: иначе можно получить изменённые права при старой revision или новую revision при откатившейся записи. Laravel предоставляет транзакционный API с rollback при исключении, но конкретный lock/порядок инкремента зависит от реализации fence и СУБД. Документация Laravel 13, Database, версия ветки 13.x; доступ проверен 2026-09-29
Практическая классификация:
- Обычные save, create, delete, restore — fence, если затронута защищённая сущность.
- Query-level mass update/delete, upsert, saveQuietly, withoutEvents — те же требования; отсутствие событий не делает запись безопасной.
- attach/detach/sync/pivot update — fence, если меняется авторизационная связь.
- Raw SQL и Query Builder — fence при изменении тех же данных; напрямую гарантировать его средствами Eloquent невозможно.
- Изменения метаданных, не влияющих на права или кешируемую интерпретацию, можно исключить из fencing, но границы должны быть явно описаны.

## Поддерживаемый mutation contract

Предлагаю определить пакетный авторизованный путь записи, например PermissionLayer::mutate(...) или отдельный mutation service: он открывает транзакцию, применяет операцию, изменяет revision в том же transaction scope и возвращает результат. Для relation-мутаторов предусмотреть именованные операции, в том числе замену набора связей (sync). Контракт должен охватывать также Filament create/edit/delete/bulk actions: ресурс сохраняет знакомый CRUD UI, но его действия делегируют запись в mutation service, а не полагаются на observer.
Для публичных моделей и отношений документация должна разделять чтение/кастомизацию и запись: приложение может использовать модели, scopes, связи и собственные Filament Resources; записи через Eloquent напрямую допустимы только если вызывающая сторона сама соблюдает контракт fencing. Предупредить, что mass operations, pivot writes, quiet saves и raw SQL обходят обычные события. Для bulk действий определить семантику fence — обычно одна revision на атомарную логическую операцию, а не обязательно на каждую строку.

## Ограничения и пробелы

Интерфейс Repository задаёт соглашение для клиентов, но не лишает их доступа к публичной модели, DB, отношениям или SQL. Поэтому он не может сам по себе предотвратить обход. Скрыть Eloquent от Filament не требуется и практически несовместимо с модельным CRUD; реалистичная цель — удобный официальный путь, интеграции для Filament, тесты на все пути записи и ясное описание escape hatch.
Неизвестно, какие именно таблицы и поля в вашей схеме влияют на решение PermissionLayer, как устроен текущий revision lock и охватывает ли он несколько соединений. Я не проверял приватный репозиторий и не утверждаю, что существующие механизмы уже соблюдают эти инварианты. Для окончательной спецификации нужно отдельно проверить каждый write path, включая кастомные pivot-модели и сторонние Filament actions.

Sources:
1. [Документация Laravel 13, Eloquent, версия ветки 13.x; доступ проверен 2026-09-29](https://laravel.com/framework/docs/13.x/eloquent)
2. [Документация Laravel 12, Relationships, версия ветки 12.x; доступ проверен 2026-09-29](https://laravel.com/framework/docs/12.x/eloquent-relationships)
3. [Filament 5.x, Resources overview, версия ветки 5.x; доступ проверен 2026-09-29](https://filamentphp.com/docs/5.x/resources/overview)
4. [Документация Laravel 13, Database, версия ветки 13.x; доступ проверен 2026-09-29](https://laravel.com/framework/docs/13.x/database)
5. [laravel](https://laravel.com/framework/docs/database)
