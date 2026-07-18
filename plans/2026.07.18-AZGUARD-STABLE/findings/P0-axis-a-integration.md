# P0 ось A — интеграционная поверхность / DX потребителя (2026-07-18)

> Слой 1. Аудитор: fable (субагент P0.2). Read-only аудит по чеклисту phases/P0.md §P0.2.
> Приоры A.1–A.5 (research/01-fluent-api-priors.md §A) сверены с кодом/доками;
> RAG-канон — findings/P0-rag-fluent-dx.md. Закрытые долги (F1–F54, T1–T7,
> INTEGRATION_FEEDBACK ✅) не дублируются — только открытые follow-up'ы.

## Чеклист

| C# | Проверка | Вердикт | Якоря | Заметка |
|:--|:--|:--|:--|:--|
| C-A1 | install/quick-start против реальных шагов (SP, миграции, конфиг); «Basic usage ≤5 строк» | partial | docs/introduction/installation.md:68; docs/introduction/quick-start.md:146 | Шаги воспроизводимы (publish-тег/миграции/трейт сходятся с SP:169,219-221); вывод doctor и версии выдуманы → A-01; порог первой проверки тяжёлый → A-06 (C-A9) |
| C-A2 | User-модель: HasAzGuard + контракт AzGuardUser, частичное подключение | partial | docs/introduction/installation.md:43; docs/introduction/quick-start.md:23 | Контракт зеркалит трейт 1:1; частичное подключение деградирует мягко (method_exists → 403); но golden path не показывает `implements AzGuardUser` → A-02. Примечание: HasAzGuard композит 2 трейтов (HasAzGuard.php:24-28), не 4, как в recon |
| C-A3 | middleware: 5 алиасов + azguard.context — читаемость строкового DSL | partial | packages/core/src/Http/Middleware/CheckDirectGrant.php:35; packages/core/src/Http/Middleware/PanelCheckAccess.php:27 | DSL плоский, не сложнее канона spatie (`role:manager,api`); azguard.check — 0 аргументов (атрибут-driven) — сильный ход; порядок аргументов асимметричен → A-03. Гэп `::using()` — ось B (C-B7), здесь не дублируется |
| C-A4 | Gate/policy: Authorizer, PolicyDiscovery + #[GateAbility] — что руками vs автоматика | pass | packages/core/src/AzGuardServiceProvider.php:187 | Gate::before и policy-discovery полностью автоматические; руками — только регистрация PanelProvider в конфиге; guard:doctor ловит дубли/сирот (AzGuardDiagnostics). Конфликт порядка Gate::before с пользовательскими before — ось C (C-C5) |
| C-A5 | Filament: AzGuardPlugin + opt-in трейты — шаги, дефолты | pass | packages/filament/src/AzGuardPlugin.php:43; packages/filament/src/AzGuardPlugin.php:56 | Подключение — одна fluent-строка `AzGuardPlugin::make()->forPanel('admin')`; дефолты разумные (panel из config, enforce=true). Конформность `make()` канону Filament (`app(static::class)`) — ось B (вход P0.1 «Filament-шов») |
| C-A6 | frontend abilities: AbilitiesDto::make/abilitiesFor + docs — путь, курированность | pass | packages/core/src/Abilities/AbilitiesDto.php:33; docs/recipes/inertia-permissions.md:193 | Двухуровневая модель (DTO per-resource + abilitiesFor app-shell) целостна; инвариант §6 соблюдён (курированный список, toArray boolean-only, server-side предупреждения). Обещание «TypeScript export» в quick-start:155 не имеет носителя — учтено в A-01 |
| C-A7 | context/multi-workspace: SP, middleware azguard.context, ResolvesContext | pass | packages/context/src/AzGuardContextServiceProvider.php:135; packages/context/config/az-guard-context.php:38 | Путь ясный: установить пакет → реализовать ResolvesContext → перечислить в config → повесить azguard.context; alias авто-регистрируется; Octane-scoped биндинги прокомментированы; тихий false hasPermissionIn закрыт (hasContextGuard + warning, docs/advanced/context.md:218). Config-список resolvers vs fluent-регистрация — ось B (C-B8) |
| C-A8 | Testing DX: FakeAzGuardUser/FakeGrantSource против канона fake-фасада | fail | docs/advanced/testing.md:140; packages/core/src/Facades/AzGuard.php:21 | Фейки есть (@api, fluent grant/wildcard), но нет `AzGuard::fake()` c ассерциями → A-04; глава Testing отрицает существование testing-kit → A-05 |
| C-A9 | headless-путь: install→трейт→Gate→abilities без Filament; guard:doctor headless | partial | docs/introduction/quick-start.md:1; packages/core/src/Guard/AzGuardDiagnostics.php:48 | Quick-start и abilities-доки не упоминают Filament — путь проходим без Filament-глав; doctor — core-команда, Filament не нужен. Но порог «одна проверка = PanelProvider+enum+каталог» остался (открытый follow-up закрытого п.4) → A-06; doctor на пустом сетапе (0 панелей) молча проходит — onboarding-подсказки из installation.md:73 не существует (учтено в A-01) |
| C-A10 | словарь поверхности: panel/guard/context/scope → сущность → видимость | partial | docs/basic-usage/multiple-guards.md:7; packages/core/src/Contracts/ScopeInterface.php:11 | Таблица ниже; «guard» — бренд-префикс без собственной сущности + doc-декларация привязки panel↔auth-guard без носителя в коде → A-07; context и scope — параллельные механизмы entity-bound прав → A-08. Решения о слиянии — P2 |
| C-A11 | doc-DX: структура docs против канона Basic usage / Testing / Advanced | partial | docs/advanced/testing.md:1; docs/recipes/integration.md:55 | Канон preseed — [UNVERIFIED]-эвристика (findings/P0-rag-fluent-dx.md «Ограничения»), применена как эвристика: Basic usage/Advanced есть, Testing не first-class — спрятан в advanced/ и противоречит recipes (testing-kit описан только в recipes/integration.md) — дефект зафиксирован находкой A-05; отдельной находки не плодим |

## Словарь поверхности (C-A10)

| Термин | Сущность-носитель | Где виден потребителю | Комментарий |
|:--|:--|:--|:--|
| panel | VO `AzGuard\Support\Panel` (packages/core/src/Support/Panel.php:13) + `PanelProvider` (packages/core/src/PanelProvider.php) | config `panels`/`default_panel`; `$panelId`-аргумент почти каждого метода; middleware `azguard.panel:`; первый сегмент ключа `app.documents.view` | Единица изоляции прав — центральный термин, носитель реален |
| guard | Собственной сущности НЕТ: бренд-префикс (config az-guard.php, artisan `guard:*`, неймспейс `Guard/` — Authorizer/PolicyDiscovery/AzGuardDiagnostics) + контракт `ContextGuard` | имена команд, имя конфига, docs/basic-usage/multiple-guards.md (= Laravel auth guards) | Коллизия с Laravel auth guard; docs декларируют «a panel is bound to one or more guards» (multiple-guards.md:7) — привязки в коде нет (Panel.php: 0 вхождений guard). Кандидат: закрепить guard=бренд, изоляция=panel → A-07 |
| context | `AuthorizationContext(Manager)` (packages/context/src/AuthorizationContext.php) + core-контракт `ContextGuard` (packages/core/src/Contracts/) | `hasPermissionIn($type,$id,$perm)`; middleware `azguard.context`; docs/advanced/context.md | Runtime-«где я сейчас» (workspace/tenant), пакет opt-in |
| scope | `HasScopedRoles` + модель `ModelHasScope` + таблица model_has_scopes + `ScopeInterface` (packages/core/src/Contracts/ScopeInterface.php:11) | `hasScopedPermission($perm,$entity)`; docs/advanced/entity-scopes.md | Persist-«роль на конкретной записи». Семантически пересекается с context → A-08 |

Кандидаты на слияние (решение — P2): 1) guard → растворить в бренд/panel (терминологически, не кодом);
2) context ↔ scope — унифицировать нарратив «права относительно сущности» (два входа:
hasPermissionIn docs/advanced/context.md:94 vs hasScopedPermission docs/advanced/entity-scopes.md:44).

## Находки

### A-01 — Install/quick-start описывают несуществующий вывод doctor и устаревшие рамки

- **Severity:** Minor
- **Чек:** C-A1
- **Где:** docs/introduction/installation.md:68-74
- **Суть:** «Expected output» doctor (`✓ Config file found…`) и список чеков в quick-start.md:146-150 («Migrations are up to date», «orphan permission keys in the database») не соответствуют реальному DoctorCommand.php:69 / AzGuardDiagnostics (проверяет панели/policy/enum, не конфиг и не миграции); installation.md:8 «Laravel 11.x or 12.x» vs composer `^11|^12|^13` (packages/core/composer.json:36); quick-start.md:155 обещает «TypeScript export», носителя нет. Потребитель сверяет свой вывод с выдумкой и теряет доверие к докам.
- **Рекомендация:** Синхронизировать примеры вывода/список чеков с фактическим doctor (или доработать doctor до обещанного, включая подсказку при 0 панелей — решать в P1/P2); поправить версии; убрать/реализовать обещание TS-export.

### A-02 — Golden path не выдаёт канонический тип: AzGuardUser отсутствует в install/quick-start

- **Severity:** Minor
- **Чек:** C-A2
- **Где:** docs/introduction/installation.md:43-50
- **Суть:** Контракт `AzGuardUser` (packages/core/src/Contracts/AzGuardUser.php:31) создан именно чтобы интеграторы не переизобретали тип актора (закрытый п.1 INTEGRATION_FEEDBACK), но installation/quick-start показывают только `use HasAzGuard;` без `implements AzGuardUser` — контракт всплывает лишь в recipes/integration.md. Потребитель главного пути получает нетипизированного актора.
- **Рекомендация:** Показать `implements AzGuardUser` в сниппетах install/quick-start как канон.

### A-03 — Асимметрия порядка аргументов в middleware-DSL

- **Severity:** Minor
- **Чек:** C-A3
- **Где:** packages/core/src/Http/Middleware/CheckDirectGrant.php:35-39
- **Суть:** `azguard.grant:permission,panel` (CheckDirectGrant.php:35-39), но `azguard.panel_check:panel,permission` (PanelCheckAccess.php:27) — порядок «что проверяем/где» перевёрнут между соседними алиасами; потребитель обязан помнить порядок per-alias.
- **Рекомендация:** Зафиксировать единый порядок аргументов для всех алиасов (направление; сам redesign сигнатур — вместе с C-B7 в P2).

### A-04 — Нет `AzGuard::fake()` с high-level ассерциями

- **Severity:** Major
- **Чек:** C-A8
- **Где:** packages/core/src/Facades/AzGuard.php:21-41
- **Суть:** Канон верифицирован (`Pdf::fake()` + assert*, findings/P0-rag-fluent-dx.md Запрос 3): одно-вызовный swap через фасад + `assertGranted/assertDenied/assertChecked` (+ closure-вариант). У azguard фасад fake() не имеет; FakeGrantSource требует ручной сборки из трёх вызовов (Testing/FakeGrantSource.php:20-24), ассерций нет вовсе — приор A.1 кодом подтверждён.
- **Рекомендация:** Спроектировать `AzGuard::fake()` (Recorder-паттерн + ассерции) — тема P1/P2, здесь только гэп.

### A-05 — Глава Testing отрицает существующий testing-kit

- **Severity:** Major
- **Чек:** C-A8
- **Где:** docs/advanced/testing.md:140
- **Суть:** «AzGuard has no fake/mock layer — test against the real resolver» — при том что `AzGuard\Testing\FakeAzGuardUser`/`FakeGrantSource` шипятся как @api и задокументированы в recipes/integration.md:55-85. Главный Testing-путь потребителя прямо противоречит поверхности пакета: читатель главы Testing не узнает о фейках вообще.
- **Рекомендация:** Перенести/продублировать testing-kit в главу Testing, убрать ложное утверждение; поднять Testing до first-class раздела (см. C-A11).

### A-06 — Headless/embedded-порог: одна проверка требует PanelProvider + каталога

- **Severity:** Major
- **Чек:** C-A9
- **Где:** docs/introduction/quick-start.md:34-119
- **Суть:** Первая рабочая проверка требует авторских 3 классов (PanelProvider, enum, Role) + регистрации в config + `guard:sync-roles`. Для embedded/headless потребителя (мост, библиотека) порог остаётся тем, из-за которого автор INTEGRATION_FEEDBACK (п.4) не смог прогнать real-e2e; закрытие «только доками» (FakeGrantSource-рецепт покрывает лишь тесты) — это открытый follow-up, продакшн-путь без каталога отсутствует.
- **Рекомендация:** Рассмотреть минимальный panel-less/lenient путь (или явный «minimal setup» quick-start) — направление для P1/P2, дизайн не здесь.

### A-07 — Термин «guard» без сущности + doc-декларация привязки panel↔auth-guard без носителя

- **Severity:** Minor
- **Чек:** C-A10
- **Где:** docs/basic-usage/multiple-guards.md:7
- **Суть:** «a panel is bound to one or more guards» — в коде привязки panel↔Laravel-guard нет (packages/core/src/Support/Panel.php — 0 вхождений guard); сам термин guard в пакете — бренд-префикс (guard:*, Guard/, az-guard) поверх коллизии с Laravel auth guard. Онбординг-налог: четыре термина при трёх сущностях.
- **Рекомендация:** Переформулировать multiple-guards.md через панели (или реализовать привязку осознанно — решение P2); закрепить в глоссарии guard=бренд.

### A-08 — context vs scope: два параллельных механизма entity-bound прав

- **Severity:** Minor
- **Чек:** C-A10
- **Где:** docs/advanced/context.md:94
- **Суть:** `hasPermissionIn('workspace', $id, $perm)` (context, runtime) и `hasScopedPermission($perm, $entity)` (scope, persisted; docs/advanced/entity-scopes.md:44) отвечают на почти один вопрос потребителя «может ли он ЗДЕСЬ», но живут как разные словари, пакеты и таблицы; выбор между ними доками не маршрутизируется.
- **Рекомендация:** Минимум — маршрутизирующий раздел «context или scope?»; кандидат унификации нарратива — кластер P2 «словарь терминов».
