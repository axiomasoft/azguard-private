# R09 — Filament 5: persistence и безопасность UI

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Filament 5 resource architecture for role/direct-grant administration: Eloquent Resource queries versus domain mutation services, handleRecordCreation/handleRecordUpdate/delete and bulk actions; transactional controls default; policy coverage, hidden UI versus authorization, Livewire lifecycle tenant switching. How to decouple writes without rewriting Filament read model? Version-specific sources needed.

## Ответ Perplexity

## Архитектурный вывод

Для Filament 5 разумная граница — оставить Eloquent Resource и его запросы как read model, а создание, изменение и удаление ролей/прямых grants направлять через доменные mutation-сервисы. Полный отказ от Filament Resource не требуется: документация позволяет отдельно настраивать запросы чтения и отдельно переопределять обработчики записи. Ниже — документированные возможности Filament 5 и Laravel; рекомендации по границе ответственности — архитектурные выводы, не утверждения о вашем приватном репозитории.

## Подтверждённые факты

- Filament 5 Resources строятся вокруг Eloquent-моделей. Запросы к модели Resource проходят через getEloquentQuery(), который можно переопределить для ограничений выборки и сохранения текущего read model. Официальная документация Filament 5, раздел Resources Overview
- На Create-странице запись по умолчанию создаётся в handleRecordCreation(array $data): Model; на Edit-странице обновление можно заменить через handleRecordUpdate(Model $record, array $data): Model. Поэтому эти методы — естественные адаптеры между UI-формой и прикладным сервисом, а не обязательное место для доменной логики.
- Filament связывает доступ к Resource и записям с Laravel policies: viewAny, create, update, view, delete, а для массового удаления — deleteAny. Для массовой операции можно отдельно проверять policy каждого выбранного объекта через authorizeIndividualRecords().
- Bulk delete загружает выбранные Eloquent-модели в память, в частности чтобы выполнять индивидуальную авторизацию и вызывать model events. fetchSelectedRecords(false) позволяет отказаться от этого поведения и выполнить удаление одним запросом, но тем самым меняет эти свойства.
- Laravel DB::transaction() фиксирует изменения при успешном завершении и откатывает при исключении; поддерживается повтор транзакции при deadlock. Laravel Database: Getting Started, опубликовано 2026-09-24
- Livewire документирует persistent middleware: стандартные middleware авторизации повторно применяются к последующим запросам компонентов; собственное middleware требуется явно добавить в список, если оно должно сохраняться между запросами.

## Архитектурная рекомендация

Оставьте getEloquentQuery() и таблицы Filament для чтения: фильтры, сортировку, пагинацию, отношения и отображение можно сохранить без изменения. В handleRecordCreation() и handleRecordUpdate() преобразуйте валидированные данные формы в явную команду и вызовите публичный доменный mutation-сервис. Результатом верните или повторно загрузите Eloquent-модель, чтобы Filament продолжил штатные redirect/notification-потоки.
Удаление лучше выразить отдельным сервисным методом, вызываемым из delete action; массовые действия — отдельной командой/операцией сервиса, а не прямым delete() или массовым update() в callback. Это особенно важно для role/direct-grant логики: сервис должен владеть проверками инвариантов, ревизионным fence, обновлением generation/envelope, обработкой контекста и атомарностью. UI-политики могут быть дополнительным барьером, но не заменяют проверку права и tenant/context внутри доменной операции.
Транзакции не следует считать включёнными автоматически для любого пути записи Filament. В доступной документации показана настройка транзакций Laravel, но не установлено, что все Resource handlers и table bulk actions автоматически оборачиваются в транзакцию. Поэтому доменный сервис должен сам задавать транзакционную границу там, где изменение grant и обновление revision/cache-generation обязаны быть единым атомарным переходом. Для bulk-команды определите семантику явно: вся пачка атомарна или допускаются частичные результаты.
Скрытая кнопка — UX, не контроль доступа. Сочетайте policy для страниц и стандартных действий с проверкой авторизации на границе каждой команды; у bulk actions учитывайте разницу между общей deleteAny() и per-record delete().

## Неопределённости и риски

Документация Livewire подтверждает повторное middleware, но сама по себе не доказывает, что ваш tenant resolver корректно переустанавливает tenant при каждом переключении контекста или очищает состояние singleton-сервисов. При Livewire tenant switching нужно проверять контекст на каждом запросе/действии, не полагаться на контекст, захваченный при mount, и сбрасывать выбранные записи/форму при смене tenant. Это вывод по модели жизненного цикла, а не подтверждённое поведение вашего приложения.
Не проверялись фактическая версия Filament в lock-файле, конфигурация ->databaseTransactions(), конкретные bulk actions, policy registration и адаптация контекстного discriminator. Приватный репозиторий не исследовался; это исследовательская записка, не аудит реализации. Дата проверки: 2026-09-29.

Sources:
1. [Официальная документация Filament 5, раздел Resources Overview](https://filamentphp.com/docs/5.x/resources/overview)
2. [Creating records](https://filamentphp.com/docs/5.x/resources/creating-records)
3. [Editing records](https://filamentphp.com/docs/5.x/resources/editing-records)
4. [Delete action](https://filamentphp.com/docs/5.x/actions/delete)
5. [Laravel Database: Getting Started, опубликовано 2026-09-24](https://laravel.com/framework/docs/database)
6. [Livewire 4 Security](https://livewire.laravel.com/docs/4.x/security)
