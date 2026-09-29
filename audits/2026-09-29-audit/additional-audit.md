# Дополнительный аудит AzGuard — взгляд Codex

Дата: 2026-09-29. Срез: `253cbf4cce0226545cd96ca8c6bcbefe329b1403`. Основание: [исходный аудит](../2026-09-29-audit.md), текущий код, сохранённые исследования и [первичные источники](Research/primary-sources.md).

## Основной вывод

AzGuard стоит перепроектировать вокруг **явного запроса авторизации, согласованного результата и расширяемых контрактов**, а не вокруг размера manager или распределения файлов по папкам. Сейчас продукт уже содержит многие необходимые механизмы: источники grants, каталог, context layer, проверку ownership для Gate, revision fence, абсолютную границу expiry и configurable models. Их нужно собрать в ясную систему с определёнными правилами композиции и записи.

С учётом позиции владельца я предлагаю рассматривать полноценный redesign. При этом «идеальная» архитектура должна позволять объяснить один запрос, одну запись и один extension без скрытой зависимости от глобального состояния. Число интерфейсов, пакетов и feature flags само по себе гибкости не доказывает.

Самостоятельно обнаружены коллизия контекстной идентичности и неполное восстановление временного контекста при исключении extension. Также есть существенные вопросы к primary/replica reads, единообразию mutation contract, provenance объяснения и композиции нескольких ограничивающих расширений.

## Что поправить в исходном аудите

| Тезис | Уточнение по текущему коду | Следствие |
|:--|:--|:--|
| Context needs PermissionLayer | `Contracts/PermissionLayer.php` уже имеет `@api`; context реализует его | Развивать существующий seam или заменить его осознанно; отсутствие seam не доказано |
| Context может отсутствовать в cache key | Resolver передаёт `cacheDiscriminator()`; `PermissionCache` включает его в request/durable identity | Проблема находится в сериализации discriminator и зависимостях его содержания |
| Context manager — singleton | Провайдер использует `scoped`; class/provider docblocks местами устарели | Lifecycle isolation уже предусмотрена; это не coroutine-local гарантия |
| Нужен `finally` для временного context | `ContextGuard::checkInContext()` уже использует `finally` | Есть узкий дефект до входа в `try`, а не полное отсутствие cleanup |
| Global Gate до policies перехватывает unknown abilities | `Authorizer::check()` проверяет `CatalogKeyMatcher::owns()` и возвращает `null` для чужих abilities | Главный незакрытый вопрос — owned ability без grant: здесь тоже `null`, а не `false` |
| Installer запускает миграции без согласия | `InstallCommand` вызывает `confirm(..., default: true)` | Общая `migrate` после подтверждения реальна; scope, default и exit code требуют отдельной оценки |
| Роль не имеет канонической identity | `RoleIdentity::persistedName()` уже задаёт `panel:name`, reserved `super-admin`; lookup class — exact | Новый immutable key возможен, но проблема теперь в связях key/class/label и lifecycle переименования |
| Нужна dependency matrix | `.github/workflows/tests.yml` уже содержит PHP/Laravel/lowest-stable, DB и Redis jobs | Добавлять consumer/package isolation и проверять resolver tuples, а не строить matrix с нуля |
| `composer install --working-dir=packages/core` обеспечит qualification | Package manifests не содержат собственного полного test harness | Нужен consumer fixture с dev-инструментами и discovery, а не одна команда install |
| Необходимы `@spi`, `@experimental` | Это может быть полезной конвенцией проекта; выбранный tooling должен её понимать | Само наличие тегов не устанавливает границу или SemVer enforcement |

Исходные числа inventory в основном подтверждаются: core — 151 тип, 32 с `@api`, 16 отдельно `@internal`, 103 без статуса; context — 19 (1 internal, 18 без статуса); filament — 25 без статуса. У facade внутри docblock имеется `@internal` cut-line для методов: [машинный инвентарь](Research/repository-inventory.json) показывает этот случай как `api+internal`, не как дополнительный internal class.

Scanner нашёл **56 прямых cross-package `use` imports**, в том числе пять разных internal core types. Это нижняя граница: inline FQN, динамический DI и phpdoc edges не перечислены.

## Самостоятельные находки

### C01 — коллизия context discriminator

**Major; воспроизведено на уровне идентичности.** В `ContextPermissionLayer::cacheDiscriminator()` применяется строка `ctx:{type}:{id}`. Контексты `('workspace:a', 7)` и `('workspace', 'a:7')` различны по `AuthorizationContext::equals()`, но имеют один discriminator. `AuthorizationContext::cacheKey()` имеет ту же неоднозначность. `AuthorizationContext` и fluent `inContext()` принимают произвольные строки без грамматики, исключающей двоеточие.

Для одного subject/panel/revision/generation такие контексты могут использовать один cached PermissionSet. Хеширование итогового payload не помогает: потеря различимости происходит до хеша. Сквозная выдача лишних прав в приложении не воспроизводилась; необходимы существующие разные grants и достижимые context selectors. Сам дефект кодирования доказан [probe](Research/behavior-probes.json).

Предпочтительный redesign: typed `ContextRef`, сериализация структуры до digest и одна общая identity policy для сравнения, БД и кэша. Decide отдельно, равны ли `7` и `'7'`; нельзя без обсуждения перенести правила SubjectIdentity на context. Research: R10, R30.

### C02 — temporary context остаётся при исключении до `try`

**Major для swappable resolver; воспроизведено с контролируемым extension failure.** `ContextGuard` сначала делает `manager->set($context)`, затем `resolver->forgetRequestCache()`, и только после этого входит в `try/finally`. Если первая invalidation бросает исключение, прежний context не восстанавливается. Контракт resolver не запрещает исключений.

Probe инжектирует resolver, который бросает на invalidation. После ошибки остаётся `'temporary'`, а не `'original'`. Это сбой cleanup в текущем execution scope; изолированный request lifecycle не устраняет последствия внутри одного запроса/задания. База и backend cache не использовались. Research: R06, R06.

### C03 — revision fence не закрепляет primary-read policy

**Условный security risk; не воспроизведён на replica.** `PermissionStateRevision::current()` делает обычный builder `first()` без `useWritePdo()`. В Laravel read/write connection может направлять SELECT на replica. Сравнение connection names в `Config` не отличает primary от read host того же logical connection.

Запрос после revoke может увидеть старую revision и прочитать старый allow из кэша. Также недостаточно закрепить primary только для revision: новый revision с отстающим grant-source read способен материализовать старые grants под новым ключом. Sticky помогает записи и чтению внутри одного request, а не читателю из другого процесса. [Laravel read/write docs](https://laravel.com/docs/13.x/database#read-and-write-connections).

Нужен архитектурный consistency contract: authoritative reads для state **и** источников, допустимые isolation levels, поведение in-flight checks, transaction-local чтения и deployment generation. Глобальный counter уже полезен; его нельзя автоматически объявлять linearizable authorization. Research: R04, R16, R28, R06.

### C04 — mutation contract не един для всех Eloquent writes

**Наблюдение по коду; последствия зависят от поддерживаемого write path.** `DirectGrant` и `ContextRole` используют `RevisionedPermissionModelWrites`. `Role` и `RolePermission` этого trait не имеют. Role-permission synchronizer bump делает; прямой `RolePermission::save()/delete()` сам по себе аналогичную гарантию не получает. Публичные relationships/pivots тоже открывают bypass paths.

Это шире, чем замечание о bulk updates. Seeding docs рекомендуют `RolePermission::firstOrCreate()`, тогда как cache docs предупреждают о raw/bulk writers. На первой установке пустой cache скрывает различие; повторная запись в работающей среде должна иметь явную семантику. Прямой model-write stale allow в этой сессии не исполнялся.

Redesign должен определить поддерживаемые mutation services, trusted maintenance/import mode и требования к стороннему storage. Одно имя Repository не запрещает raw SQL. Нельзя обещать live-safe «reset после commit»: между commit и reset остаётся окно, если отсутствует maintenance barrier. Research: R03, R04, R12, R28.

### C05 — `explain()` не объясняет единый evaluated snapshot

**Наблюдение по коду; diagnostic correctness.** `Authorizer::explain()` получает effective set, затем отдельно опрашивает global sources в `resolveWinningSource()`. Эти вызовы могут видеть уже другой state. Grant, пришедший только из context layer, не является global source, поэтому attribution может отсутствовать. Context restriction и источники вне normal resolver topology не отражены как единая цепочка provenance.

`AccessDecision::allowed=false` также не равен финальному Laravel Gate deny: `check()` на no-grant возвращает `null`, после чего policy может разрешить. UI/CLI должны различать local decision и final host decision.

Предложение: `evaluate(request) -> Decision` с state token, reason, contributions/constraints; `allows()` и `explain()` — проекции одной семантики. Trace может быть opt-in и не обязан хранить все чувствительные значения. Research: R08, R18, R29.

### C06 — ограничивающее расширение допускается в единственном binding

**Наблюдение по контракту; composability gap.** `PermissionLayer` документирует single implementation; context provider привязывает себя к нему. Второе plugin ограничение — membership, licensing или organizational scope — не имеет согласованного места и порядка. Добавление двух bindings не равно композиции.

Нужен либо один явный configurable composition root, либо ordered layer/constraint registry с конфликтами, идентичностью и lifecycle. Порядок должен входить в deployment/policy identity, если меняет результат. Research: R19, R20, R26, R29.

### C07 — global wildcard обходит contextual narrowing

**Подтверждённая семантика, требующая продуктового решения; не объявлена новой уязвимостью.** В resolver глобальный wildcard возвращается до `PermissionLayer::apply()`. Это явно описанный superadmin shortcut. Поэтому названия `ContextOnlyStrategy` и `DenyWithoutContextStrategy` не означают абсолютное tenant ограничение для wildcard subject.

Если context является только способом выбора grants, это допустимо. Если context должен задавать security boundary, bypass требует самостоятельного `SuperAdminPolicy`/break-glass contract. Лучше разделить grant aggregation и обязательные constraints, чем пытаться выражать оба через один PermissionSet. Research: R07, R20, R22, R29.

### C08 — panel registry принимает неоднозначные и mutable definitions

**Major для registry identity; воспроизведено.** `Panel::id()` проверяет только длину; приняты `''`, `'a.b'`, `'*'`, строка с newline. `registerPanel()` заменяет существующий объект без ошибки. Изменение `id('changed')` после регистрации оставляет registry key `'app'`.

Нужны валидированный namespace identifier, immutable registered definition и explicit replacement policy. Можно сохранить fluent builder, завершая его `build()`, либо выбрать другой typed constructor. Точная grammar — решение владельца продукта, а не обязательный стандарт Laravel. Research: R10, R19, R27.

### C09 — PHPStan `@internal` не является package-boundary gate

**Major для достоверности enforcement.** Комментарий ApiBoundaryTest связывает native проверку с Composer packages. Текущие [PHPStan docs](https://phpstan.org/writing-php-code/phpdocs-basics#internal-symbols) описывают границу top namespace и доступность с определённой версии/режимом. Все три пакета используют корень `AzGuard`; нельзя считать Composer разделение достаточным enforcement.

Исходный тест core-only и проверяет часть public signatures. Он не запрещает context/filament imports internal classes. Для новой структуры нужны явные dependency rules по packages/modules и проверки полного избранного API. `@spi` можно оформить как API для extension authors, но обеспечить правила отдельным manifest/checker. Research: R01, R02, R14.

### C10 — успех installer не зависит от exit code migration

**Наблюдение по коду; условная operational ошибка.** `InstallCommand::handle()` игнорирует результат `$this->call('migrate')`, далее сообщает Installed и возвращает `SUCCESS`. Если nested command возвращает non-zero без exception, wrapper даст ложный успех. Существующие installer tests проверяют success paths; ошибочная migration в этой сессии не исполнялась.

Новая install semantics должна явно определить publish/config validation, migration scope, interactive/noninteractive поведение и propagation exit codes. Research: R12, R14.

### C11 — модель гибкости сильнее реального storage contract

**Архитектурный вопрос.** Core поддерживает настроенные subclasses/tables и проверяет общую connection. Context layer читает `DB::table()` на default connection и фиксированную ContextRole implementation. Filament create path явно берёт `auth.providers.users.model`. Эти механизмы не складываются автоматически в поддержку alternate auth providers, tenant-specific databases или interchangeable storage.

Нужно выбирать одно из ясных обещаний: Eloquent extension contract с фиксированными columns/atomicity; либо самостоятельный storage port и явный Eloquent adapter. Любой вариант допустим при полной переработке. Hidden global reads и модельный inheritance как случайный SPI затрудняют оба. Research: R03, R09, R13, R28, R09.

### C12 — качество tooling нельзя сводить к одному проценту

**Подтверждённая область отчёта.** Mutation gate использует `--covered-only` и исключает Commands/Facades, а для Filament также Resources/Pages. В исключённых директориях находятся поведенческие mutation handlers; основание «только declarative» не всегда описывает реальное содержимое.

Число 99/100% относится к выбранным mutants, а не ко всем сценариям библиотеки. Это не означает, что score сфальсифицирован. Нужны понятный denominator/scope, rationale исключений, security scenario coverage и consumers. Snapshot defaults записывает как `= default`, не фиксируя значения; custom semantic checks всё ещё нужны. Research: R02, R14, R15, R14.

## Мои архитектурные рекомендации

### Явная идентичность и семантика раньше directory layout

Запрос авторизации должен однозначно отвечать: кто субъект; какой namespace/panel; какое действие; какой контекст; какой ресурс; когда и против какой версии state принимается решение. Не все поля обязаны существовать в каждой проверке. Но implicit current panel, unqualified string и request-global context не должны иметь разные случайные трактовки у model trait, Gate adapter и CLI.

Для writes нужен **Actor отдельно от Subject**. Выдача права человеку не означает разрешение текущему администратору выдавать любое право, менять срок или выходить за tenant. Эти проверки должны жить в server-side administration policy и одном application entry point, используемом CLI/UI/import.

### Configurable policies и стабильные invariants

Настраивать стоит membership resolver, policy composition, superadmin scope, default panel, catalog providers, subject mapping, storage adapter и cache strategy. Нельзя настраивать выключение identity uniqueness, атомарности write+revision или проверок expiry, продолжая обещать прежние гарантии.

Конфигурация должна иметь validation, defaults, override precedence и диагностику конфликтов. `ModelClass`, table mapping и arbitrary `Connection` — разные уровни расширяемости, не взаимозаменяемые knobs. Рекомендую явные capability contracts и boot-time composition validation.

### Разделить read model UI и writes

Filament Resource естественно работает с Eloquent. Ради чистоты ядра можно выделить публичные adapter models/projections, а mutation направлять через application API. Другая возможность — service-backed Pages, если действительно выбран arbitrary storage. Это сознательный обмен genericity на native UX, не запрет UI dependencies.

### Полноценный redesign разрешён, но его смысл нужно демонстрировать

Полезные критерии идеальной архитектуры: добавить новый grant source без core edits; добавить constraint без перезаписи context provider; сменить auth provider без правки resource; использовать explicit context в queue; получить тот же local decision через Gate/model/CLI; заменить storage при выполнении объявленных capabilities.

Новые DTO/VO/interfaces принимаются, если упрощают эти истории. Отдельный contracts/kernel package возможен; решение не должно быть продиктовано привычкой сохранять три существующих пакета. Сравнение — в [architecture-options.md](architecture-options.md).

## Что передать в приоритетную проработку Opus

1. Identity encoding, scope cleanup и registry invariants: уже имеются конкретные воспроизведения.
2. Authorization algebra: ownership/abstain/deny, mandatory constraints, superadmin, membership и delegation.
3. Mutation/storage consistency: authoritative reads, transaction/revision capabilities, события и external writers.
4. Чистый public API, честный SPI и configurable composition root; затем выбранная структура пакетов.
5. Consumer fixtures, semantic contracts, upgrade/clean-install scenarios, measurement-based cache topology.

Это порядок обсуждения решений, не подробный план исполнения. Для каждого выбора полезно написать короткий counterexample: какое опасное поведение он исключает и какую extension story делает возможной.
