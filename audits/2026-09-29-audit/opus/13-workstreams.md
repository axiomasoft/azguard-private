# 13 — Рабочие потоки: фазы и пункты для плана

Нумерация `Pn.m` стабильна: на неё ссылаются сценарии [14-verification.md](14-verification.md). План создаётся по
протоколу проекта (`plans/ACTIVE.md`); фазы и пункты переносятся **без перенумерации**.

Коротко, в каком порядке. Пакет ещё не в работе, поэтому старый код не патчится: новая версия строится с нуля по
досье. Сначала основа: ядро понятий (F1), панели и правило выбора панели (F2), хранилище (F3). На ней — проверка
прав со всеми механиками (F4), затем модель и изменения (F5). В конце — Laravel-поверхность, Filament, интеграции и
выпуски (F6–F8).

## 0. Правила исполнения (переносятся в `Execution Rules` плана)

1. **Совместимости с 0.3 нет** ([D01](02-decisions.md#d01)): без алиасов, нормализатора старого конфига, миграции
   данных. Старый код удаляется в том пункте, где появляется замена.
2. **Probes — регрессионные требования.** Каждый probe из [evidence](evidence/README.md) переписывается на новый API
   с **обратным** ожиданием (дефект невозможен) в пункте, указанном в колонке «Probe». Пункт без такого теста не
   закрыт.
3. Исполнитель читает только указанные разделы досье; расхождение досье и кода → вопрос в `open-questions.md`, не
   импровизация.
4. Пункты с маршрутизацией **opus** не отдаются младшим моделям без готовой спецификации (`findings/Pn.m.md`).
5. После каждого пункта: `composer test`, Pint, PHPStan (уровень проекта), arch-тесты; падение или пропуск ≠ успех.
6. **Встроенные механики пишутся только через публичные контракты** (arch-тест из
   [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему)). Не хватает контракта — сначала контракт.
7. Имена — только из [03](03-glossary-and-renames.md).
8. Код Vaulter и других пакетов из этого плана **не** меняется. Идея моста и заметки
   ([10 §9–§10](10-integrations.md#9-идея-моста-vaulter--azguard)) передаются в план Vaulter отдельной задачей.

## F0 — Подготовка

| Пункт | Что | Готово когда | Модель |
|---|---|---|---|
| P0.1 | ADR «Ecosystem conventions» (текст из [10 §8.1](10-integrations.md#81-общие-инженерные-правила-adr-ecosystem-conventions-один-текст-в-репозиториях-экосистемы)) в `docs/adr/` | ADR в репозитории; предложение передано владельцам других пакетов | sonnet |
| P0.2 | Сценарии probes → спецификации регрессионных тестов на новом API (`tests/Regression/*.md` → тесты по ходу F1–F7) | у каждого probe есть файл-спецификация с пунктом, который его закроет | sonnet |
| P0.3 | Каркас consumer-фикстуры (чистый Laravel + собранные архивы) в CI | job работает (пока без блокировки) | sonnet |
| P0.4 | Composer-имена `axiomasoft/azguard`, `axiomasoft/azguard-filament`; `homepage` → `github.com/axiomasoft/azguard`; context-пакет помечен к удалению | `composer validate` зелёный | sonnet |

## F1 — Ядро понятий

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P1.1 | `Kernel\Identity\*`: `PermissionKey`, `PermissionPattern`, `RoleKey`, `SubjectRef`, `ContextRef`, `AnyContext`, `ActorRef`, `IdentityCodec` | [05 §5](05-php-api.md#5-значения-ядра-azguardkernel), D07 | property-тесты однозначности и round-trip (V01–V04) | P07 | opus |
| P1.2 | Грамматика: `panel:local`, ≥ 2 сегментов, шаблоны `*`/`**`, без голых звёздочек | D18 | таблица грамматики как data-provider | P14 | sonnet |
| P1.3 | Значения решения: `AccessRequest`, `Decision`, `DecisionReason`, `Contribution`, `StateToken`, `Explanation`, `PermissionSet` | 05 §5 | unit-тесты значений | — | sonnet |
| P1.4 | Arch-правила зон | [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему), D02, D12 | arch-набор зелёный | — | sonnet |

## F2 — Панели

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P2.1 | `PanelProvider`, `PanelBuilder`, `Panel`, `PanelRegistry` (заморозка, дубликаты, `replace`, `configure`, `configureAll`), `CurrentPanel` | [05 §4](05-php-api.md#4-описание-панели-panelprovider-и-panelbuilder), D06 | V05–V07 | — | opus |
| P2.2 | `PanelResolver` — одно правило выбора панели; панель по умолчанию; `azguardDefaultPanel()` | [09 §1](09-authorization-semantics.md#1-как-выбирается-панель), D05, D11 | V64 (свойство P9), V65 | P01c, P05, P09 | opus |
| P2.3 | Настройки панели: провайдер → плагины → `configurePanels()` → конфиг; `PanelSettings` с источником значения; гарантии не настраиваются | D45, [07](07-configuration.md) | V47 | — | opus |
| P2.4 | Плагины: `Plugin`, `DependsOnPlugins`, `BasePlugin`/`keyPrefix`, жизненный цикл, конфликты, отпечаток | [06 §1](06-extension-points.md#1-плагин), D47 | V48 | — | opus |
| P2.5 | Каталог: построители enum/классов/плагинов, `#[Describe]`, O(1)-поиск, коллизии, `azguard:catalog:cache` | D36 | V08; бюджет D44 для чужой ability | — | opus |
| P2.6 | Модули: `registerPanel()`, `configurePanel()`, `AmbiguousPanelException` | [06 §7](06-extension-points.md#7-модули-и-сторонние-пакеты-внутри-приложения), D50 | V55 | — | sonnet |

## F3 — Хранилище

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P3.1 | `Storage`, `StorageRegistry`, `Storage::mutate(panel, …)` с повторами и порядком блокировок, версия **на панель**, `ids.host_keys` | [08 §1](08-data-model-and-migration.md#1-хранилища--простыми-словами), [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы), D24, D34 | запись без `mutate` невозможна для официальных путей; повтор на deadlock (PG) | — | opus |
| P3.2 | Миграции с префиксом хранилища на PG/MySQL/MariaDB/SQLite; `azguard:storage:migration` | [08 §2](08-data-model-and-migration.md#2-схема-хранилища), D35 | DDL-снимки на 4 СУБД; два хранилища в одной БД не мешают друг другу | — | sonnet |
| P3.3 | Базовые модели (`Role`, `RolePermission`, `RoleAssignment`, `DirectPermission`), свои модели панели, `azguardFields()`, `meta`, `decisionFields` | [08 §3](08-data-model-and-migration.md#3-свои-модели-и-колонки), [06 §5](06-extension-points.md#5-свои-модели-и-поля), D46 | V51 | — | opus |
| P3.4 | Защита от прямых записей (`UnsupportedDirectWriteException` в local/testing, предупреждение в production) | D22 | тест обоих режимов | — | sonnet |

## F4 — Проверка прав: механики и пайплайн

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P4.1 | Пайплайн проверки: подготовка, before-хуки, решение, ограничения, after-хуки; `EvaluationContext`; «ошибка = отказ» | [09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм), [06 §3](06-extension-points.md#3-хуки-проверки), D17, D20, D48, D55 | свойства P1–P10 (V15), V53 | — | opus |
| P4.2 | Механика «код»: `grantToAll`, `CodeRole`, `AssignedAutomatically`, `formerKeys` | [05 §6](05-php-api.md#6-роли-в-коде), D14, D52 | V09–V11, V66 | P02 | opus |
| P4.3 | Механика «политики и Gate»: `#[Decides]`, `policies()`, `discoverPolicies()`, `gates()`, `ConsultsGrants::granted()` | [05 §7](05-php-api.md#7-политики), [09 §5](09-authorization-semantics.md#5-политики-и-gate-внутри-проверки), D53 | V67–V68 | P03 | opus |
| P4.4 | Механика «БД»: роли из БД, назначения (роли из кода и БД по ключу), прямые права, шаблоны, сроки | D13, D52 | контрактный набор источников зелёный; V74 | P08 | opus |
| P4.5 | Механика «связи сущностей»: `relation(model, via, role)`, видимость через `whereHas` | [06 §2.1](06-extension-points.md#21-связи-сущностей), D52 | V69 | — | opus |
| P4.6 | Контексты: `ContextPolicy`, `on:` → контекст/ресурс, `ContextAware`, резолверы, `withinContext`, членство | [09 §3](09-authorization-semantics.md#3-политика-контекстов-панели), D15, D16 | V12–V14, V16 | P06, P06b | opus |
| P4.7 | Суперадмин: правило панели (роль, класс, замыкание), `defaults.super_admin`, `isSuperAdmin()` | [09 §4](09-authorization-semantics.md#4-суперадмин), D19 | V17 | P01a, P14 | sonnet |
| P4.8 | Кэш наборов прав (запрос + store), `StateToken`, отпечаток, `Volatility`, `state_refresh`, `touch()` | [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность), D24, D25 | V18–V19, V49, бюджеты D44 | P10, P10b | opus |
| P4.9 | `decideMany()` | [09 §9](09-authorization-semantics.md#9-пакетная-оценка), D27 | бюджет запросов D44 | — | opus |
| P4.10 | `explain()` + `azguard:explain` | [09 §11](09-authorization-semantics.md#11-объяснение), D29 | V27 | — | sonnet |
| P4.11 | `GateBridge` (правило выбора панели, режим панели, одно слово → `null`) | [09 §7](09-authorization-semantics.md#7-gate-laravel), D26 | V28–V30 | P03, P09 | opus |
| P4.12 | `Visibility` + `ContextAware::scopeVisibleTo` | [09 §10](09-authorization-semantics.md#10-видимость-visibleto), D31 | V31–V33 | P04a–c | opus |

## F5 — Модель и изменения

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P5.1 | Трейт `HasAzGuard`, `SubjectAccess`, `SubjectPanels`, контракт `AzGuardSubject` — имена как в Spatie | [05 §1](05-php-api.md#1-модель-трейт-hasazguard), D09, D10 | V70; рецепты «Если вы пришли из Spatie» зелёные | — | opus |
| P5.2 | Пайплайн изменений: `Change`, `ChangeResult`, проверка данных, хуки `changing`/`changed`, запись | [06 §4](06-extension-points.md#4-хуки-изменений), D22, D49 | каждая операция: транзакция, новая версия, повтор без события | P13 | opus |
| P5.3 | `RoleManager`: create/update/delete/syncPermissions/renameKey, `expectedFingerprint` | [05 §2](05-php-api.md#2-панель-azguardpanel), D14 | V71 | — | sonnet |
| P5.4 | События после commit; плагин `azguard/audit` | [08 §6](08-data-model-and-migration.md#6-каталог-событий), D28 | V34–V35 | P11 | sonnet |
| P5.5 | Схема панели `PanelSchema` (+ `toArray()`) | [05 §8](05-php-api.md#8-схема-панели), D54 | V72; JSON-снимок фикстуры | — | opus |

## F6 — Laravel-поверхность и удаление старого

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P6.1 | Фасад, `PanelAccess`, `AzGuard::actingAs()` | [05 §2–§3](05-php-api.md#3-фасад), D09 | рецепты README зелёные | sonnet |
| P6.2 | Middleware `azguard.panel` (вход, право входа, текущая панель и сущность), `azguard.can`; удаление старых middleware и директив `@az*` | D32 | V73; снимок alias'ов | sonnet |
| P6.3 | `config/azguard.php`, `AzGuardConfig`, проверки при загрузке | [07](07-configuration.md), D33 | V36 | sonnet |
| P6.4 | Doctor: проверки на панель и хранилище, проверки плагинов | [12 §2](12-operations-and-release.md#2-doctor-проверки) | `azguard:doctor --json` снимок | sonnet |
| P6.5 | `azguard:install` | D40 | V37 | sonnet |
| P6.6 | Команды | [12 §1](12-operations-and-release.md#1-команды), D38 | снимок команд | sonnet |
| P6.7 | Тестовый kit, `AzGuardFake`, контрактные наборы | D39, [06 §9](06-extension-points.md#9-контрактные-наборы-azguardtestingcontracts) | наборы проходят против встроенных реализаций | sonnet |
| P6.8 | Генераторы `azguard:make:*` и стабы | 12 §1 | сгенерированный код проходит контрактные наборы | sonnet |
| P6.9 | Удаление старого: context-пакет, `GrantBuilder`, `HasScopedRoles`, `PolicyAttributeRegistrar`, generated policies, abilities DTO, matcher'ы, `NullSafeUniqueIndex`, старые таблицы и конфиг | D01, D03 | arch: старые имена отсутствуют | sonnet |

## F7 — Filament

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P7.1 | Плагин без глобального конфига; `guardPanel()`, `manages()`; права ресурсов — плагином `azguard/filament` | [11 §2](11-filament.md#2-плагин) | V24 | sonnet |
| P7.2 | `FilamentGate`, ключи по slug, страницы и виджеты закрыты без права | 11 §3–§4 | V25; уникальность ключей | sonnet |
| P7.3 | `RoleResource` по схеме панели | 11 §5.1 | V23, V61 | opus |
| P7.4 | `RoleAssignmentResource`, `DirectPermissionResource`, выбор панели, поиск субъектов и сущностей, «Почему?» | 11 §5.2 | V26, V63 | sonnet |
| P7.5 | Свои поля из схемы, `FilamentFormExtension` | 11 §6 | V62 | opus |
| P7.6 | `PanelsPage`, `DoctorPage` | 11 §5.3 | снимок страниц | sonnet |
| P7.7 | `azguard:filament:generate` | 11 §7 | снимок генерации | sonnet |

## F8 — Интеграции, документация, выпуски

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P8.1 | `IntegrationContractTests`, пример интеграции `fixtures/example-integration` в CI | [10 §6–§7](10-integrations.md#6-проверка-интеграции), D51 | V58–V60 | opus |
| P8.2 | Документация и рецепты ([12 §6](12-operations-and-release.md#6-документация)) | D42 | рецепты = сниппеты | sonnet |
| P8.3 | Гейты совместимости (manifest, снимки, пример интеграции) | 12 §5, D41 | гейты в CI | sonnet |
| P8.4 | Consumer-фикстуры (a)–(d) и матрица | 12 §4 | зелёная матрица | sonnet |
| P8.5 | Выпуск **1.0.0-beta.1** + abandoned-пометки старых пакетов | D01, D03 | тег; анонс | sonnet |
| P8.6 | Передать идею моста и заметки V1–V6 в план Vaulter | [10 §9–§10](10-integrations.md#9-идея-моста-vaulter--azguard) | задача в плане Vaulter создана | sonnet |
| P8.7 | Обкатка beta: кабинет + админка + кабинет продавца + модуль + API; Octane, queue, Redis, PG/MySQL; внешнее ревью API | D01 | чек-лист закрыт | opus (ревью) |
| P8.8 | **1.0.0**: Roave BC Check включён | D41 | все гейты блокирующие | sonnet |

## Зависимости

```
F0 ─► F1 ─► F2 ─► F3 ─► F4 ─► F5 ─► F6 ─► F7 ─► F8
            │           │
            │           └── P4.1 (пайплайн) нужен до всех механик P4.2–P4.5
            └── P2.4 (плагины) нужен до встроенных механик: они — плагины
P2.2 (выбор панели) нужен до P4.11 (Gate) и P5.1 (трейт)
P4.11 требует P2.5 (O(1)-поиск в каталоге);  P4.12 требует P4.4, P4.5, P4.6
P5.5 (схема) требует P4.2–P4.5 (механики описывают себя)
F7 требует P5.1–P5.5;  P8.1 требует F4–F5
```
