# AzGuard (axiomasoft/azguard-private) — консолидированный аудит готовности к продакшну

Дата: 2026-10-29. Версия в репозитории: 1.0.0 (релиз 2026-10-14) + несовместимые изменения в [Unreleased].

## 0. Что прочитано

Корень: README, CHANGELOG, ROADMAP, SECURITY, composer.json, audits/2026-10-10-acceptance.md.
Ядро (packages/core/src): Authorization/Internal/Authorizer, ReadAttempt, Cache/PermissionSetCache,
Pipeline/Stages/{PrepareStage, BoundaryStage, AuthorityStage}, Authorization/Visibility,
Kernel/Internal/PatternMatcher, Sources/Database/DatabaseSource, Sources/Database/Internal/LockedReads,
Laravel/Internal/Http/{RouteChecks, Middleware/EnterPanel, Middleware/CheckPermission}.
CI: workflows/tests.yml, release.yml, dependabot-auto-merge.yml; структура tests/.

Не прочитано (ограничение инструмента — файлы читаются по одному, клонирования нет):
BatchEvaluation, BatchInputs, EvaluationFrame, GrantWriter, ActionWriter, Storage/Internal/*, Policies, Catalog,
Panels DSL, Filament-пакет, тесты. Для этих частей выводы опираются на приёмочный аудит владельца.

## 1. Вердикт

Ядро архитектурно готово к продакшну. Обходов авторизации («allow без гранта») не найдено.
Найдено: 5 проблем кода средней тяжести (2 требуют проверки тестом), 3 мелких, 8 проблем процесса/supply-chain.
Главный системный риск — отсутствие mutation-гейта над самым сложным кодом (ReadAttempt/кэш) и SemVer-нарушение
в [Unreleased].

## 2. Сильные стороны (не трогать)

- Fail-closed везде: любая ошибка источника/политики/рестрикции -> deny/failed с типизированной причиной.
- Единый pipeline: Prepare -> Boundary -> Authority(sources, superadmin, membership, policy) -> Restrictions -> After.
- Защита от рекурсии: ключ операции включает panel/subject/permission/scope/Fiber id.
- PatternMatcher: посегментное сравнение, regex из входа не строится; `p.*` = ровно один сегмент, `p.**` = один и более.
- ReadAttempt валидирует каждую контрибуцию источника (панель, scope, тип) -> InvalidSourceContributionException;
  бюджет selections() 1000 ссылок; снапшотные чтения на одном PDO для DecisionSet.
- LockedReads: все запросы с явными предикатами panel/tenant/origin; id валидируется regex `(role|permission):[1-9][0-9]{0,18}`.
- PermissionSetCache: unserialize с allowed_classes, записи с validUntil = min(TTL, ближайший expires_at).
- BoundaryStage: несовпадение tenant/context ресурса и запроса -> deny (TenantMismatch/AssignmentScopeMismatch);
  ModelKey::matches защищает от подмены записи scope.
- EnterPanel: контекст восстанавливается в finally; strict-режим -> 403 на маршрут без проверки (в local/testing — исключение).
- CI: матрица PHP 8.3–8.5 x Laravel 12/13 x prefer-lowest/stable; PG/MySQL/MariaDB; реальная реплика; Redis; consumer-fixture;
  release.yml требует все гейты + split до публикации.

## 3. Находки в коде

| # | Файл | Проблема | Тяжесть | Исправление |
|---|------|----------|---------|-------------|
| C1 | AuthorityStage::walk() vs Visibility::build() | walk() принимает не-grantable роль при EvaluatesAutomaticRoles + GrantedAutomatically; Visibility — нет. decide() и visibleTo() расходятся для автоматических ролей | Высокая | Единый ContributionQualifier для обеих веток; property-тест эквивалентности decide() <-> visibleTo() |
| C2 | Visibility::visibleTo() | `->withoutGlobalScopes()` на итоговом запросе снимает SoftDeletes и тенантные скоупы хоста, если PredicateCompiler не применяет их внутри кандидата | Высокая (проверить) | Тест с SoftDeletes + кастомный глобальный скоуп; при провале — applyScopes() до переноса |
| C3 | Visibility::build() | Не учитывает former_keys ролей, не логирует отброс (walk() пишет Log::notice) | Средняя | В рамках C1 |
| C4 | PermissionSetCache | In-process $sets растёт без лимита (эвикция только при смене state/forgetPanel) -> утечка в Octane/queue-worker | Средняя | LRU-лимит или сброс на RequestTerminated/JobProcessed |
| C5 | PermissionSetCache::decode() | Записи в общем store (Redis) не подписаны; компрометация Redis = инъекция грантов | Средняя | HMAC(APP_KEY) поверх serialize(); несовпадение = промах |
| C6 | AuthorityStage::walk() catch | `isset($source)` может указывать на источник предыдущей итерации -> неверный component в explain | Низкая | Обнулить $source / ловить внутри итерации |
| C7 | AuthorityStage::decide() | `new TenantMembershipRestriction($this->container)` на каждый вызов; в Authorizer она инжектится | Низкая | Инжектировать |
| C8 | DatabaseSource | app(AzGuardConfig/PanelRegistry/StorageRegistry) внутри методов #[Api] класса — сервис-локатор | Низкая | Инжектить при bindPanel() |
| C9 | CheckPermission middleware | Response::deny(..., $decision?->reason->value) кладёт причину (source_error и т.п.) в code исключения; док заявляет «никогда не несёт объяснения» | Низкая | Либо убрать код, либо уточнить документацию |
| C10 | BoundaryStage::resolveTenant/Context | tenant/context берутся из container->make(Request::class) — вне HTTP (jobs, CLI) резолверы получают пустой Request; поведение корректно (null), но неочевидно | Инфо | Явно документировать/тест для queue |

## 4. Процесс, релиз, supply chain

| # | Проблема | Почему важно | Действие |
|---|----------|--------------|----------|
| P1 | [Unreleased] удаляет Laravel 11, меняет тип StorageDescriptor::$connection, делает конструкторы internal, меняет API-manifest | Нарушение собственных правил SemVer после 1.0.0 | Версия 2.0.0 или вернуть совместимость |
| P2 | Mutation-гейт не зелёный, запускается вручную, MSI не получен; SIGSEGV PHP при генерации мутантов и один раз на MariaDB | Самый сложный код (ReadAttempt, кэш, batch) не проверен на «пустые» тесты | Обязательный mutation для Authorization/Internal, Kernel, Sources/Database/Internal; MSI >= 85; расследовать SIGSEGV (зафиксировать PHP+pcov+opcache в Docker) |
| P3 | dependabot-auto-merge: авто-мерж minor-обновлений, contents: write, action без SHA-пина | Supply-chain для библиотеки авторизации | Только patch; SHA-пин всех actions; условие — зелёный tests.yml |
| P4 | Все workflows используют actions по тегам (@v6, @v2, @v4, @v5), в tests.yml нет persist-credentials: false | Supply-chain | SHA-пин; zizmor уже есть — довести до нуля замечаний |
| P5 | composer.json: "laravel/pao": "*" | Нефиксированная dev-зависимость | Зафиксировать |
| P6 | SECURITY.md ссылается на axioma-studio/azguard (репозиторий — axiomasoft) | Приватный репорт невозможен | Исправить ссылку |
| P7 | 8 npm-уязвимостей в docs (6 high) | Шум в Dependabot/SBOM | Обновить |
| P8 | SPLIT_ENABLED / MONOREPO_SPLIT_TOKEN не настроены | release.yml не опубликует | Настроить, прогнать на pre-release теге |
| P9 | Нет Octane-job в CI (есть «Octane-safe» в README) | Состояние синглтонов (PermissionSetCache, CurrentContext) между запросами не проверяется | Smoke-тест Octane/RoadRunner: 1000 запросов, разные тенанты, проверка утечки контекста и памяти |

## 5. ROADMAP: что вернуть до 1.x

- Обязательный morph map (Relation::requireMorphMap()): переименование класса меняет идентичность субъекта -> гранты теряются молча. Минимум — error в azguard:doctor.
- SBOM + подписанные релизы (GitHub attestations): ожидаемый минимум для пакета авторизации.
- Subject-level locking: хотя бы бенчмарк contention в CI с порогом, пока блокировка panel_state.

## 6. План закрытия (порядок)

1. C1–C3: общий квалификатор контрибуций + тест эквивалентности decide()/visibleTo() (автоматические роли, former_keys, SoftDeletes, глобальные скоупы).
2. C4–C5: HMAC и лимит памяти кэша; Octane-smoke (P9).
3. P2: mutation-гейт для трёх директорий, расследование SIGSEGV.
4. P1: решить 2.0.0 vs совместимость.
5. P3–P8: supply-chain и гигиена (один PR).
6. Прогон release.yml на теге v1.0.1-rc.1 / v2.0.0-rc.1.

## 7. Что ещё стоит прочитать для полного покрытия

BatchEvaluation/BatchInputs (гонки epoch в DecisionSet), GrantWriter/ActionWriter (идемпотентность записи, уникальные индексы),
Storage/Internal/Storage + StorageReadSession (snapshot isolation на MySQL/MariaDB: REPEATABLE READ vs READ COMMITTED),
Policies/Internal/PolicyDecider (обход через Gate::before), Filament-пакет (bulk actions, exports, relation managers), миграции (индексы по
panel+tenant_key+subject_type+subject_id, expires_at).
