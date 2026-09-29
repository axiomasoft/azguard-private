# F33 — documented-rolepermission-write

**Статус:** точечный Perplexity synthesis; первичные факты сверяются отдельно, ответ не является доказательством поведения репозитория.

## Данный контекст и пробел

Earlier R03 covered generic Eloquent event bypass. Current RolePermission model has no RevisionedPermissionModelWrites. RolePermissionSynchronizer does bump DB revision after its own diff. Published docs/advanced/seeding.md uses RolePermission::firstOrCreate() directly; docs/advanced/cache.md warns only about raw SQL/bulk writes and says official mutation paths bump. A warmed permissions cache may survive later direct RolePermission::firstOrCreate() or delete.

## Вопрос

Decide whether documented direct Eloquent writes should remain supported. Compare (A) route all role-permission writes, including seeders and Filament, through a transactional application mutation API and explicitly deprecate direct writes; (B) wrap model save/delete in revision coordinator; (C) database trigger/state table. Show a concrete warmed-cache test, rollback semantics, pivot/bulk bypass contract, and why a post-commit reset leaves a window. Cite primary Laravel Eloquent and transactions docs; avoid generic repository advice.

## Ответ

Решение: выбрать A. Не поддерживать прямые RolePermission::firstOrCreate() и delete() как официальный путь изменения данных: направить изменения из синхронизатора, сидеров и Filament через транзакционный application API, который меняет записи и DB-ревизию в одной транзакции. В документации пометить прямые записи как deprecated. Это проектное решение: если гарантировать инвалидацию кэша — обязательный инвариант, пусть его обеспечивает явная граница записи, а не необязательное поведение конкретного способа сохранения модели.

## Сравнение вариантов

- A — mutation API. Лучше всего выражает инвариант и позволяет атомарно менять данные вместе с ревизией. Требует миграции всех официальных callers и договорённости, что обход API — неподдерживаемая запись.
- B — координатор в save/delete модели. Покрывает обычные сохранения и удаления отдельных моделей, но не даёт полного перехвата: массовые Eloquent update/delete не вызывают модельные события, поскольку модели не загружаются. Поэтому как единственная гарантия для всех способов записи этот вариант недостаточен.
- C — триггер и таблица состояния. Может охватить записи, сделанные вне Eloquent, но переносит инвариант в БД, добавляет DB-специфичную логику и требует согласовать ревизию с транзакцией изменений. Выбирать его стоит, только если прямые записи из разных клиентов — поддерживаемое требование, а не случайный обход приложения.

## Тест и транзакционные гарантии

Прогреть permissions cache, зафиксировать ревизию, затем вызвать прямой RolePermission::firstOrCreate() и проверить, что ревизия не изменилась и кэш по-прежнему содержит прежнее разрешение. Повторить с удалением модели. Это демонстрационный regression test пробела; после выбора A эти операции должны либо быть переведены на API, либо тестироваться как запрещённые/неподдерживаемые пути.
В API изменения и bump ревизии должны выполняться в одном DB::transaction(). При исключении Laravel откатывает транзакцию; значит, откатятся и запись, и ревизия. Не сбрасывайте кэш только после commit: между commit записи и сбросом другой запрос может прочитать старый кэш при уже новой БД. Инвалидируйте через транзакционную ревизию, проверяемую читателем, либо обеспечьте синхронную согласованную схему чтения; один afterCommit оставляет окно.

## Контракт обхода

sync, сидеры и Filament вызывают API; прямые Eloquent-записи deprecated. Pivot-операции (attach/detach/sync) и bulk/raw SQL не считаются автоматически покрытыми модельными событиями: либо выполнять их внутри API с bump ревизии, либо явно запрещать как write path. Laravel подтверждает, что массовые Eloquent-операции не dispatch-ят соответствующие события моделей.

Sources:
1. [Laravel Eloquent](https://laravel.com/framework/docs/eloquent)
2. [Laravel Database Transactions](https://laravel.com/framework/docs/database)
