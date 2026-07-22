# 03 — Канон P2: целевая структура + fluent/DX грамматика (2026-07-18)

> Слой 2. Авторский синтез (fable, design pass 3). Вход детализации фазы P2. Источники
> слоя 1: findings/P0-axis-a-integration.md · P0-axis-b-fluent.md · P0-axis-d-structure.md ·
> P0-rag-fluent-dx.md (RAG-каноны). Партиция кластеров — research/02-backlog.md (D9).
> Развилки разрешены по прямому указанию владельца: «идеальная структура, любые breaking
> разрешены, применять лучшие решения» (brief/00-brief.md п.7/п.9; refinements 2026-07-18) —
> plan.md §5 D14. Каждое несущее решение несёт RAG-маркер (SKILL §7).

## 0. Принцип фазы

Пре-1.0 свобода (бриф п.7) + «максимальная надёжность / fail-closed» (D10) + «идеальный
fluent, современные практики» (бриф п.9). Все развилки, где RAG-канон однозначен, решаются
в его пользу; где RAG не диктует (доменная семантика) — в пользу максимальной консистентности
и честности к домену. Инварианты ARCHITECT_REVIEW.md §6 «What NOT to Do» — жёсткая граница
любого редизайна (union-only, курированный frontend, контракты только на реальных швах,
прямые предикаты остаются одним вызовом).

## 1. Целевая доменная структура core (кластер 5 D-05 + корневые типы + субдомен контрактов)

Роспуск `Support/` (catch-all из 6 ролей — D-05) по доменам-приёмникам. Карта — из
findings/P0-axis-d-structure.md §C-D1 (LOC/классификация подтверждены аудитом), приёмники
уточнены до целевых неймспейсов:

| Файл (сейчас) | LOC | Целевой неймспейс | Обоснование |
|:--|--:|:--|:--|
| Support/Panel.php | 154 | `AzGuard\Panels\Panel` | VO панели — ядро домена Panels/ |
| Support/PanelResolver.php | 106 | `AzGuard\Panels\PanelResolver` | резолвер panel-id — тот же домен |
| PanelProvider.php (корень) | — | `AzGuard\Panels\PanelProvider` | базовый класс панель-провайдеров — сосед VO |
| Support/PermissionName.php | 55 | `AzGuard\Permissions\PermissionName` | резолвер ключа — рядом с существующим Permissions/ |
| PermissionKey.php (корень) | — | `AzGuard\Permissions\PermissionKey` | VO грамматики ключей — тот же домен |
| Support/Config.php | 320 | `AzGuard\Configuration\Config` | типизированный config-аксессор (23 потребителя) — свой дом |
| Support/RequestState.php | 54 | `AzGuard\Runtime\RequestState` | request-scoped стейт (@internal) — инфра рантайма |
| Support/ScopedRoleCache.php | 38 | `AzGuard\Runtime\ScopedRoleCache` | request-scoped кэш (@internal) — инфра рантайма |
| Support/ResolvesGateAbilities.php | 29 | `AzGuard\Abilities\ResolvesGateAbilities` | Gate-хелпер — рядом с потребителями (AbilitiesDto) |
| Support/BladeHelper.php | 13 | `AzGuard\Auth\BladeHelper` | Blade auth-check — домен Auth/ (существует) |
| Support/Schema/MorphColumns.php | 48 | `AzGuard\Database\Schema\MorphColumns` | схемный хелпер миграций — свой дом |

**Итог:** `Support/` УПРАЗДНЯЕТСЯ (пустой неймспейс не остаётся). Корень core остаётся
каноничным Laravel-пакетом: `AzGuardManager.php` + `AzGuardServiceProvider.php` (RAG:—
repo-grounded: findings/P0-axis-d-structure.md §C-D2 «канон, на месте»).

**Субдомен контрактов (D-04, кластер 9-структура).** Решение: `AzGuard\Contracts` (16
cross-cutting публичных контрактов) и `AzGuard\Registry\Contracts` (6 контрактов
registry-субдомена) — ДВА ДОМА СОЗНАТЕЛЬНО (locality: контракт живёт рядом со своим
субдоменом — глубже модуль, чем плоский общий `Contracts/`). Слияния НЕТ. Инвариант
ArchTest «contracts are interfaces» расширяется на ОБА неймспейса — эту правку делает P1-W2
(D-04, backlog 02); P2.1 только фиксирует канон двух домов и проверяет, что расширение P1
покрыло Registry\Contracts (RAG:— repo-grounded: tests/ArchTest.php:21, research/02-backlog.md
W2 D-04).

## 2. Контрактные швы (кластер 9, D-09)

6 «структурных» baseline-записей phpstan (findings/P0-axis-d-structure.md §C-D7 «структурные»)
— не шум, а обход типовой границы на реальных швах. Разрешение — уточнение контракта/generics,
НЕ снятие baseline «как есть» (RAG:— repo-grounded: phpstan-baseline.neon:22,52,148,172,184,190):

| Baseline-запись | Шов | Направление разрешения |
|:--|:--|:--|
| Authorizer::check $user Authenticatable≠Authorizable (:22) | Guard/Authorizer | сузить/выровнять тип параметра до пересечения контрактов (Authorizable) либо явный VO актора |
| ServiceProvider::panel() notFound (:52) | Panels-шов | объявить метод в контракте панель-провайдера |
| Authenticatable::$roles notFound (:148) | User-модель | доступ через контракт `AzGuardUser`/трейт, не через голый Authenticatable |
| PermissionDefinition::label() notFound ×2 (:172,:184) | Registry\Contracts | добавить `label()` в контракт `PermissionDefinition` |
| Model::dbPermissions() notFound (:190) | Roles-шов | объявить `dbPermissions()` в контракте роли/модели |

После разрешения baseline-записи УДАЛЯЮТСЯ (не остаются мёртвыми). Остальные 17 «снимаемых»
и 12 «легитимных» baseline — НЕ предмет P2 (17 → P1-W2 быстрая волна; 12 — природа пакета).

## 3. Grant-грамматика: единый immutable fluent-корень (кластер 3, B-03 + B-08)

Развилка разрешена: **immutable-with + единый корень core↔context + TTL-парность** (D16;
владелец — «лучшие решения», канон F49 для builders, канон spatie для границы типов).

**Единый корень.** Один вход `AzGuard::forUser($user)` для core И context — context НЕ
отдельный `new ContextGrantBuilder`, а fluent-расширение того же корня через `->inContext()`.
Грамматика читается как фраза (приор B.6):

```php
AzGuard::forUser($user)
    ->on('admin')                       // string|BackedEnum $panelId
    ->inContext('workspace', 42)        // опционально: context-расширение (пакет context)
    ->until($expiry)                    // TTL-парность: доступно и в context-ветке
    ->grant('orders.edit');             // терминал: string|UnitEnum
```

**Immutable-with.** Builders — `final readonly`; каждый скоуп-сеттер (`on/inContext/until/
ttl`) возвращает НОВЫЙ инстанс (не `return $this`-мутация). Терминальные глаголы явные
(`grant/revoke/revokeAll/grants`) — канон уже частично соблюдён (findings/P0-axis-b-fluent.md
§C-B9). Арх-ратчет `toBeFinal()->toBeReadonly()` расширяется с `Registry\Values` на
`AzGuard\Grants` и context-builder-неймспейс (RAG:— repo-grounded: tests/ArchTest.php:120-122;
канон F49). Immutable-with — консистентно с уже-immutable Values (RAG:— repo-grounded:
findings/P0-axis-b-fluent.md §C-B9).

**TTL-парность (D15/D16).** ContextGrantBuilder получает `until()/ttl()` и `active()`-фильтр
в `grants()` симметрично core (findings/P0-axis-b-fluent.md §C-B5 «TTL только в core»).
Требует колонку `expires_at` в context-хранилище грантов → НОВАЯ миграция (не правка
применённых, канон C-16/migrations-правил). Мотив: потребитель мультиворкспейса не учит две
грамматики одной операции (B-03).

**Фасадные позиционные shorthands.** `grant()/revoke()/grants()` на фасаде (findings/
P0-axis-b-fluent.md §C-B4 #15-17) — позиционные дубли fluent-корня → `@internal` (остаются
для внутренней оркестрации, скрыты из публичных docs). Публичный путь = fluent-корень. Полная
cut-line-спека фасада — P2.5 (исполнение выреза — P3).

## 4. Config→fluent (кластер 4, B-06 + B-02 + A-03)

Развилка разрешена по RAG-канону (все три верифицированы P0.1, findings/P0-rag-fluent-dx.md):

**Filament-плагин (B-06).** Канон v5 (RAG:✅ 2026-07-18 filamentphp 5.x panel-plugins):
поведенческие опции — fluent-сеттеры на объекте плагина (`return $this`) + геттеры, чтение в
`register()`; `make()` через `app(static::class)` (swap в рантайме); config — только
fallback-дефолты. Перенести из config в fluent: `enforce`, `source`, `abilities`, шаблон
ключа `'{panel}.{resource}.{ability}'`, `case` (RAG:— repo-grounded: findings/P0-axis-b-fluent.md
§C-B8, packages/filament/src/AzGuardPlugin.php:28-43).

**Middleware `::using()` (B-02).** Канон spatie v6 / Laravel 10.9+ (RAG:✅ 2026-07-18
laravel-permission v6 middleware + framework PR #52679): статические конструкторы
`::using(string|BackedEnum ...)` на параметризуемых middleware (grant/panel/panel_check/check);
строковый alias-DSL остаётся ПАРАЛЛЕЛЬНЫМ путём (оба в docs). Даёт типизацию, автодополнение,
enum-вход на маршрутах (findings/P0-axis-b-fluent.md §B-02).

**Единый порядок аргументов (A-03).** Все middleware-алиасы — единый порядок `что,где`
(permission, panel): выровнять `azguard.panel_check:panel,permission` под
`azguard.grant:permission,panel` (RAG:— repo-grounded: findings/P0-axis-a-integration.md §A-03).
Согласовать с сигнатурой `::using()`.

## 5. Локус фасада: cut-line target-спека (кластер 2, B-05)

Классификация 17 @method готова (findings/P0-axis-b-fluent.md §C-B4). P2.5 ПРОИЗВОДИТ
target-спеку (исполнение выреза — P3, backlog cluster 2). Целевые вердикты (RAG:— repo-grounded:
findings/P0-axis-b-fluent.md §C-B4):

- **Удалить** (0 docs + 0 внутренних потребителей, пре-1.0 свобода): `tryPermission`,
  `panelIdForPermission`.
- **`@internal`** (позиционные дубли fluent — §3): `grant`, `revoke`, `grants`.
- **`@internal`** (предикаты-дубли трейта): `isSuperAdmin` (локус — трейт); `hasContextGuard`
  — полезен без user (2 docs-исп.) → оставить публичным, но задокументировать локус.
- **Оставить** (оркестровый вход): registerPanel/getPanels/panel/currentPanel/setCurrentPanel/
  permission/registerGrantSource/registerCatalogBuilder/abilitiesFor/forUser.

Спека пишется в `root/contracts/facade-cutline.md` (вход P3).

## 6. Testing DX: AzGuard::fake() (кластер 7, A-04)

Развилка разрешена: **строим в 0.3.0** (D14; подтверждённый гейтом кластер, акцент брифа —
интеграционная поверхность). Канон верифицирован (RAG:✅ 2026-07-18 laravel-pdf v2 testing —
findings/P0-rag-fluent-dx.md Запрос 3): фасадный fake + high-level ассерции, Recorder-паттерн:

- `AzGuard::fake()` — одно-вызовный swap менеджера на записывающий double (как `Event::fake()`).
- Ассерции: `assertGranted($user, $key)`, `assertDenied($user, $key)`, `assertChecked($key)`
  — каждая принимает closure-предикат-вариант (`fn (Decision $d) => ...`) параллельно
  простой форме (канон Pdf::fake assert*).
- «get log»-метода нет — только ассерции (канон spatie).
- FakeAzGuardUser/FakeGrantSource остаются (не ломаются) — fake() их дополняет высокоуровнево.

## 7. Headless-порог (кластер 8, A-06)

Развилка разрешена: **doc-only minimal-setup** (D14; fail-closed сохраняется — прод-путь
по-прежнему требует панель/каталог, ослабления изоляции нет; рантайм panel-less — YAGNI,
анти-приор 14). Состав:

- Новый `docs/introduction/headless-quick-start.md` (+ RU-зеркало): минимальный путь
  install→трейт(`implements AzGuardUser`)→один минимальный PanelProvider→`$user->can()`/
  `abilitiesFor()` без Filament-глав.
- `guard:doctor` — подсказка при 0 панелей (onboarding-hint): различает «пустой сетап» от
  ошибки. Купля с A-01 (P1-W2 синхронизирует примеры вывода doctor к реальности): P1 убирает
  выдуманный вывод, P2 добавляет реальный hint + описывает в headless-quick-start (RAG:—
  repo-grounded: findings/P0-axis-a-integration.md §A-06, §A-01).

## 8. Пере-оценка отложенных breaking (кластер 6, C-06/C-07 = F22; + F4/F40/F51)

**Wildcard-флип (F22, C-06/C-07).** Развилка разрешена — **флип дефолта на Hierarchical
сейчас** (D18; вердикт аудита C-C4 «делать сейчас» + гейт D9 + пре-1.0 свобода): дефолтный
matcher = `HierarchicalPermissionMatcher` (`*`=сегмент, `**`=рекурсивно); legacy
(`WildcardPermissionMatcher`, `*`→`.*`) — opt-out через флаг `features.wildcard_permission`
на ОДИН deprecate-цикл. `PermissionSet` вне контейнера (C-07) дефолтит на Hierarchical (не
legacy) — standalone-результат сходится с приложением; расхождение задокументировано на
@api-границе (RAG:— repo-grounded: findings/P0-axis-c-correctness.md §C-06/§C-07,
packages/core/src/Registry/Matching/, PermissionSet.php:129).

**F4/F40/F51 — verify-record.** Аудит C-C4 подтвердил: F4 сделан (AbilitiesDto.php:45),
F40 сделан (PermissionCatalog.php:61), F51 сделан (префикс `guard:` на 22 командах) — кода
не требуют, P2.9 фиксирует верификацию в Completion Notes (RAG:— repo-grounded:
findings/P0-axis-c-correctness.md §C-C4).

**C-15 (winningSource) — НЕ в P2.** Backlog направил C-15 в P1-W2 как ЗАПОЛНЕНИЕ поля
(fill в explain()), не удаление; в P2 он попадал ТОЛЬКО при решении «удалить поле» — решение
«заполнить» (fail-closed, честный explain) оставляет C-15 в P1 (RAG:— repo-grounded:
research/02-backlog.md W2 C-15).

## 9. Кластер 1 — словарь терминов (A-07, A-08)

- **guard=бренд (A-07).** Собственной сущности у «guard» нет — бренд-префикс поверх коллизии
  с Laravel auth guard. Решение: закрепить в глоссарии `guard=бренд`, изоляция=`panel`;
  `docs/basic-usage/multiple-guards.md` переформулировать через панели (привязку
  panel↔auth-guard в коде НЕ вводить — её нет и не нужна). RAG:— (repo-grounded:
  findings/P0-axis-a-integration.md §A-07, Panel.php: 0 вхождений guard).
- **context↔scope (A-08).** Два механизма entity-bound прав (runtime context vs persisted
  scope). Решение: маршрутизирующий раздел docs «context или scope?» + единый нарратив «права
  относительно сущности» (НЕ слияние механизмов — они разные: runtime vs persist). Глоссарий
  фиксирует различие. RAG:— (repo-grounded: findings/P0-axis-a-integration.md §A-08).

Глоссарий и routing — `root/glossary.md` (судьба — docs, SKILL §3).

## 10. Порядок и связность (coupling внутри фазы)

1. P2.1 (структура) — ПЕРВЫМ: переезды неймспейсов рябят во все остальные items (их
   Required Reads ссылаются на новые пути).
2. P2.2 (контрактные швы) — после P2.1 (швы ссылаются на контракты, часть которых переезжает).
3. P2.3 (грамматика) → P2.5 (cut-line фасада) — cut зависит от решения shorthands→@internal.
4. P2.10 (docs+arch) — ПОСЛЕДНИМ: консолидация паритета EN/RU + arch-тестов после всех правок.
5. Coupling с P1: D-04 (arch Registry\Contracts) и A-01 (doctor examples) — делает P1; P2
   надстраивается (двух-домовый канон / doctor-hint), не переделывает.
6. Coupling с P3: P2 производит финальные имена/структуру + `root/contracts/facade-cutline.md`
   + `root/glossary.md` — вход cut-line/заморозки P3 (contract-block фазы).
