# AzGuard перед 1.0: полный аудит репозитория и последний разумный рефакторинг

**Дата:** 2026-10-15. **Код:** `2ce692f74c210e81426e9367303701516b1cc3f0`. **План:** `2026.10.10-№1-AZGUARD-CORE-STABILIZATION`, закрыт 2026-10-14. **Режим:** аудит и проектирование следующего изменения; реализация приложения не менялась.

## 1. Решение о готовности

**Я бы сохранил выбранную модульную архитектуру и сделал ещё один ограниченный проход исправлений перед окончательным freeze. Выпускать текущий код как окончательно стабилизированный 1.0 пока не рекомендую.** Главный остаточный риск — разные пути использования одних прав иногда дают разные ответы. Подтверждены три блокирующих расхождения: SQL теряет входы restrictions, SQL обходит custom structural resolver, batch смешивает различимые поля grants. Во всех трёх случаях одиночная проверка отказывает, а альтернативный путь разрешает. Кроме того, тот же объект пользователя считается superadmin одним методом и обычным пользователем другим.

Это не вывод из числа классов, недостаточного процента покрытия или личного предпочтения другой архитектуры. Воспроизводимые случаи приведены ниже. Штатный SQLite-набор проходит **4 919 тестов / 396 051 assertion**, серверная database-lane — **по 762 теста** на PostgreSQL, MySQL и MariaDB, отдельная engine/race-lane — **по 71 тесту** на каждом. Работает чистая установка архивов core+Filament и core-only. Эти проверки подтверждают большую часть уже построенной системы, но не обнаруживают несколько важных комбинаций публичных расширений.

Наиболее ценный последний рефакторинг — завершить существующие границы: одинаковые входы и authority в scalar/batch/query/helpers; точный storage identity; корректная сериализация полей; полная защита интерфейса; замкнутый API/SPI и detector несовместимых изменений. Новая система плагинов, разнос каждого модуля в Composer-пакет или переписывание всего движка для этого не нужны.

## 2. Что проверено и что означает «полный»

Проверка охватывает оба пакета и три модуля, публичные entry points и расширения, хранение/транзакции/тенантность, Laravel/Filament, consumer installation, тестовую инфраструктуру, docs и delivery, а также завершённый план и его доказательства. Это аудит по слоям и критическим цепочкам исполнения, а не формальное доказательство каждой строки программы.

Инвентарь текущего дерева: 512 PHP-файлов под `packages`, 429 файлов `*Test.php` под `tests`; committed manifest содержит **265 core-типов и 22 Filament-типа**, всего **1 178 опубликованных методов**. Уменьшение этих чисел не является целью: значительная часть поверхности — значения, исключения и контракты. Важна её осмысленность и способность поддерживаться после freeze.

| Область | Как проверена | Практический результат |
|---|---|---|
| Bootstrap, panels, configuration, catalog | Providers, compile/freeze, scoped/singleton bindings, public builders, README и contracts | Архитектура согласована; основные вопросы относятся к exposed types и полноте examples |
| Решения | Scalar stages, batch inputs/read coordinator, query compiler, role/source helpers, негативные consumer-сценарии | R01, R02, R07–R09 |
| Изменения и хранение | Public grant → validation/pipes → transaction → participants → revisions/events, schema/fields/tenant identity | R03–R06 |
| Scopes/Audit/DevTools | Declaration/SPI, structural resolve, directories, options, prune, migrations, generators | R02, R04–R06, R09 и CLI часть R15 |
| Filament/Laravel | Реальные Livewire actions/hydration/widgets, Gate/middleware/queue code, consumer HTTP tests, enum generator | R10–R13, R18 |
| API freeze и поставка | Attributes/manifests/comparator, отрицательные PHP/CLI-пробы, workflows, archives, plan evidence | R14–R17 |
| Работоспособность | SQLite, три серверных движка, race-lanes, Redis, чистые consumer приложения, docs/style/type checks | [Полная таблица](VALIDATION.md) |

Исходники и результаты независимых частей сверены основным агентом на критических находках; подтверждающие scripts повторно запущены. Исторический статус `done` и зелёные старые logs не использовались вместо проверки кода.

## 3. Сильные решения, которые стоит сохранить

1. **Один владелец authority writes.** Финальная валидация после pipes, проверка continuation, panel-state lock, revisions/epoch и отложенные события образуют понятную границу. Дефект R03 требует усилить проверку имени таблицы, а не возвращать произвольный writer SPI.
2. **Tenant и assignment scope разделены по смыслу.** Core владеет идентичностью, режимами и структурной проверкой; Scopes отвечает за eligibility. Это даёт способ поддерживать модельные и внешние scope без смешения tenancy и роли.
3. **Модули включаются по панели.** Core-only устанавливается из архива и работает без modules. Audit получает ограниченный транзакционный writer; его журнал не превращён в authority source. Физическое извлечение модуля остаётся отдельным решением о поставке.
4. **Отказ отличается от сбоя.** `Decision`, failure reason и opt-in `DecisionResponder` позволяют приложению различать бизнес-отказ и недоступность источника. Не стоит скрывать ошибки пустыми списками или разрешающим fallback.
5. **Практические инструменты потребителя.** Panel DSL, backed enums, code roles, `InteractsWithAzGuard`, doctor/explain, query filtering и optimistic fingerprint редактора уже дают связный продукт.
6. **Широкая проверка среды исполнения.** Реальные драйверы и races, archive-consumer, module boundaries, format fixtures и compatibility baseline полезнее одной метрики покрытия. Их надо дополнить конкретными контрпримерами, а не заменить новой бюрократией.

## 4. Приоритетные находки

Шкала: **Blocker** — доказанное нарушение обязательной границы авторизации, препятствующее принятию; **Major** — существенный дефект или незавершённый обязательный контракт; **Minor** — ограниченная ошибка/издержка. «До freeze» означает рекомендуемую очерёдность этой доработки. Уверенность в воспроизведённых runtime-находках высокая; design-выборы названы отдельно.

| ID | Вес | Сценарий и наблюдение | Что сделать | Доказательство |
|---|---|---|---|---|
| R01 | Blocker | `Restriction` читает `matchingGrants()`: scalar запрещает все 4 записи, `visibleTo()` возвращает все 4 | Сохранить одинаковую семантику authority для policy/restriction в SQL либо явно отказать в unsupported случае | [A1](parts/authorization.md), [лог](evidence/repro-matching-grants.log) |
| R02 | Blocker | Custom scope resolver отвергает scope; SQL обходит resolve при global tenant и `tenantOwner=false` | Проверять structural resolve независимо от наличия tenant owner | [A2](parts/authorization.md), [лог](evidence/repro-structural-resolver.log) |
| R03 | Major | Participant с `tables=['ROLE_GRANTS']` на SQLite пишет в core table; у чужого subject нет effect/revision, доступ появляется | Каноническая grammar table id и проверка ownership до выдачи capability | [ST-01](parts/storage-extensions.md), [лог](evidence/repro-storage-final.log) |
| R04 | Major | Storage pinned на A, descriptor хранит `null`; после смены default Audit prune удаляет строки в B | Descriptor должен обозначать разрешённое connection storage | [ST-02](parts/storage-extensions.md) |
| R05 | Major | Public `new AuditOptions(-10)` проходит override и prune удаляет свежий журнал | Инвариант retention в самом value object | [ST-03](parts/storage-extensions.md) |
| R06 | Major | `Field::multiple()` для JSON-column проходит schema/UI, запись падает `Array to string conversion` | Законченный двусторонний field codec либо compile-time запрет композиции | [ST-04](parts/storage-extensions.md) |
| R07 | Major | На одном переданном User `decide` и `isSuperAdmin`/`hasRole`/`roleNames` используют разные состояния модели | Одинаковая политика held Model vs bare SubjectRef во всех helpers | [A3](parts/authorization.md), [лог](evidence/repro-given-model.log) |
| R08 | Blocker | Fields `1` и `1.0` имеют один batch key: scalar `[granted, not_granted]`, batch `[granted, granted]`; binary string дополнительно ломает batch fail-closed | Типосохраняющий ключ contribution; одинаковый допустимый домен values | [A4](parts/authorization.md), [fail-open](evidence/repro-batch-numbers.log), [binary](evidence/repro-batch-fields.log) |
| R09 | Minor | Scope directory предлагает `project:1`, `describe` того же option возвращает null | Search/describe/write используют одну structural eligibility | [A5](parts/authorization.md), [лог](evidence/repro-directory-resolver.log) |
| R10 | Major; design | Одних `view_any`/`view` хватает для `attach`: pivot изменяется, parent update запрещён | Явные write abilities для relation operations, проверка owner и target | [Filament](parts/filament-laravel.md), [5 probes](evidence/repro-filament-final.log) |
| R11 | Major | `Resource::getWidgets()` пропущен keys/enforcement; обычный widget реально рендерится в enforced resource | Полный каталог statically discoverable widgets и явный контракт dynamic widgets | [Filament](parts/filament-laravel.md) |
| R12 | Minor | После revoke `azguard-roles.view` Livewire `$refresh` остаётся 200; новый mount — 403 | Повторная проверка view при каждом чтении/рендере страницы | [Filament](parts/filament-laravel.md) |
| R13 | Minor | Рендер 25 реальных grants даёт 40 одинаковых SELECT к users: subject и часть actors совпадают | Request-local dedup описаний субъектов | [Filament](parts/filament-laravel.md) |
| R14 | Major | Поддерживаемые `DoctorContext::config/storages` возвращают неподдерживаемые Internal objects; ещё accidental publication | Замкнуть типы API/SPI и скрыть wiring members | [DD-01](parts/plan-delivery.md) |
| R15 | Major | Comparator пропускает reference return, removal readonly, CLI array mode, PHPDoc alias change | Дополнить представление контрактов и negative drills | [DD-02](parts/plan-delivery.md), [probe](evidence/delivery/compatibility-blind-spots.php) |
| R16 | Major; qualification | Frozen SHA и текущий HEAD различаются production/validation файлами; открытый R1 review не закрыт старым gate | Новая итоговая квалификация выбранного кандидата | [DD-03](parts/plan-delivery.md) |
| R17 | Major; tooling | PHPStan `trait.unused` и Rector nonexistent rule делают обязательные команды красными; type coverage сообщает 100% при потерянных results | Анализировать public trait; согласовать toolchain/config и проверять полноту measurement | [Проверки](VALIDATION.md) |
| R18 | Minor | Два widget-класса с одинаковым basename и разными keys генерируют конфликтующие imports; итоговый enum не парсится | FQCN/уникальные aliases и проверка PHP до замены файла | [FIL-04](parts/filament-laravel.md), [генератор-проба](evidence/filament/enum-marks-probe.php) |

R01/R02 дают именно сверхразрешающий список, R08 — сверхразрешающий batch для допустимых и различимых PHP values. Для R03 нужен зарегистрированный доверенный PHP participant; удалённая атака посетителя не заявляется. R04 требует смены default DB после инициализации storage. R05 — неправильная конфигурация через опубликованный API. R10 — сознательно реализованное нынешнее поведение, подтверждённое и штатным тестом; изменить нужно саму семантику до freeze. R12 — продолжение чтения уже открытой read-only страницы, не доказанное получение изначально неизвестных данных. R15 — доказанные слепые зоны guard, а не уже выпущенные несовместимые версии. Эти различия важны для оценки риска.

### 4.1 Почему SQL-находки требуют общего решения

`Visibility::build()` самостоятельно строит authority branches, policy и restrictions. В частности, `packages/core/src/Authorization/Visibility.php:203` передаёт `withAuthority([], $admin)`: адаптер видит пустые `matchingGrants()`. Скалярный `EvaluationContext` на том же запросе содержит grant fields. Пример ограничения использует один общий predicate/check helper и всё равно получает несовместимые входы. Исправление «передать один grant в массиве» ещё не доказывает корректность при нескольких contributions: нужен договор о том, какой набор authority виден policy/restriction для каждой resource row.

`ResourceContextMapping.php:205` возвращает `P::pass()` до ветки custom resolver. Отсутствие tenant-owner проверки не означает, что любой context структурно существует. Это другой инвариант. Он нужен и спискам, и directory, и writer validation.

Целевой принцип: **разные способы исполнения должны разделять определения входов и правил, а SQL должен честно сообщать unsupported, когда точное совпадение невозможно**. Нельзя чинить список фильтрацией уже полученной страницы: это сломает totals, pagination, aggregates и bulk operations.

### 4.2 Почему storage findings относятся к API freeze

`StorageDescriptor::connection` — смысл публичного значения. После 1.0 менять `null=resolve current default` на `resolved physical target` и тип поля сложнее. `Field::multiple()` задаёт допустимую комбинацию DSL, а не косметику writer implementation. `AuditOptions` с public constructor обещает пригодный для исполнения typed value. Поэтому выбор семантики этих трёх мест должен предшествовать последнему manifest baseline.

При этом SQL-блокировки и схема таблиц не требуют общей переработки. Серверные race tests прошли; в просмотренном коде есть pinning read snapshot, единый state lock, deterministic revision updates и after-commit publication. Нужны адресные исправления границ, а не новая persistence framework.

## 5. Сопоставление с последним планом

План уже создал полезную основу: D7/D8/D10 разделили core algebra, декларативные расширения и единственный writer; P2 выделил API/SPI; P3 собрал реальные независимые slices; P4 сделал manifests, machine formats, archive fixtures и qualification. **Находки не отменяют эту работу, но показывают, какие её утверждения пока сильнее реализации.**

| Гарантия / владелец | Состояние на HEAD | Закрытие |
|---|---|---|
| Scalar/batch/query/write/directory дают одну интерпретацию; P2.7–P2.9, P3 | Базовые parity tests проходят, расширенные public scenarios R01/R02/R07/R08/R09 расходятся | Добавить cross-path contracts на реальные callbacks/fields/resolvers |
| Единственный authority writer; D8/D10, P2.11 | Главный pipeline сохраняет границу, table alias R03 обходит guard | Canonical table identity и негативные participants |
| Storage descriptor / самостоятельный Audit; P2.3, P3.5–P3.6 | Relocation-схема существует, mutable default DB R04 расходится с pinned storage | Physical identity и two-connection regression |
| Typed options; P2.5/P2.11 | Readonly форма проверяется, AuditOptions value invariant обходится | Проверка в конструкторе value |
| Supported-surface closure; D9/D15/I4 | Manifest свежий; замыкания возвращаемых Internal types нет | R14 + consumer DoctorCheck |
| Semantic BC; D26/I18/P4.3/P4.5 | Автоматический gate wired, но R15 пропускается | Полный structural snapshot и negative controls |
| Candidate qualification; P4.8/P4.9/D27 | Есть проверенные старые evidence; HEAD отличается, review оставил последующую квалификацию | R16/R17 и final candidate receipt |

Для карты фаз точные владельцы отдельных находок приведены в приложениях; сокращение `P2.11` в этом отчёте везде относится к полному ID **`2026.10.10-№1-AZGUARD-CORE-STABILIZATION`**.

В существующей истории не обнаружена подмена evidence: существуют все проверенные **267 ссылки**, совпадают **59 SHA-256 логов P4**. Ошибка — переносить квалификацию старого кандидата на нынешний код автоматически. Frozen baseline — `3d789538c65710ff77cc13bfc3109312b7f9e455`; pointer commit — `5ec7a7948c2099db249f3c0349eb4f25949f74ba`. Позднее изменились 11 non-plan files. Review P4 честно оставил новый прогон для исправления `ContractWorld::rowCounts()` на Laravel 11 × PostgreSQL. Эта комбинация не покрывается текущей DB matrix, начинающейся с Laravel 12, и в данном аудите отдельно не запускалась.

Действующие D19/D24/D25 задают fresh-install и старт публичной истории с 1.0. Возвращать миграцию пользователей с 0.7, сравнение скорости с 0.7 или отменённый schema-1 adoption в новый scope не требуется.

## 6. Как должен выглядеть итоговый продукт для потребителя

### 6.1 Один рекомендуемый путь для каждого уровня

Оставить три понятных уровня, не умножая параллельные DSL:

| Потребитель | Рекомендуемый вход | Гарантия |
|---|---|---|
| Приложение с Eloquent User | `HasAzGuard`, `hasPermission`, `grantRole`, `guard('panel')` | Короткий обычный путь; те же правила, что у explicit API |
| Сервис, job, multi-panel/tenant код | `AzGuard::panel(...)->inTenant(...)->for(...)` и typed `AccessRequest` | Панель/tenant/origin заданы явно, объекты доступа не мутируют соседнюю partition |
| Автор расширения | Названный Implement SPI + ограниченный Call context | Нужные данные доступны через стабильные типы; расширение сужает/наблюдает, не подменяет authority |

Существующий explicit API уже хорош для обычного кода:

```php
// Существующие формы API; имена модели и enum принадлежат приложению.
$access = AzGuard::panel('workspace')->inTenant($team)->for($user);
$result = $access->grantRole(EditorRole::class, on: $project);
$decision = $access->decide(PostPermission::Update, $post);
```

«Идеально» здесь означает, что helpers того же `$access`, route checks, batch и список видят те же исходные данные и возвращают согласованные ответы в своей области. `permissionSet()` при этом остаётся набором grants, а не готовым решением policy: эту полезную разницу нужно сохранить и ясно объяснять.

Для фоновых jobs документировать explicit tenant/scope, как уже делает guide. Для ресурса tenant-bound панели всегда нужен mapping ownership; неизвестный resource не следует молча считать принадлежащим выбранному tenant.

### 6.2 Матрица реальных сценариев

| Сценарий | Что уже работает | Что должно стать условием окончательной готовности |
|---|---|---|
| Одна admin-панель, enum, role, policy, маршрут | Чистый archive install, HTTP 200/403, grants и RefreshDatabase | Пройти Quick start с созданием Post/table/controller без неявных prerequisites |
| SaaS: tenant и scope внутри tenant | Explicit wrappers, membership, scope modes и writer validation | R02/R04/R09; option допускается structural validation при том же LookupContext и неизменном состоянии; stale/revoked selection по-прежнему отвергается |
| Загруженная модель с automatic role | Основной scalar путь использует переданный экземпляр | R07: роль и superadmin helpers видят тот же экземпляр; fresh DB lookup только для bare ref |
| Список, totals, bulk и export | SQL predicates и protected bulk/export machinery присутствуют | R01/R02; policy/condition/restriction parity на нескольких grants и custom resolvers |
| Внешний источник / нестандартные fields | Source SPI, typed identities, source failure handling | R08: valid values не ломают только batch, identity key не склеивает разные значения |
| Плагин журнала / transactional outbox consumer | Append-only participant внутри той же транзакции | R03/R04/R05; чужие authority tables недоступны через выданный writer |
| Поле списка в редакторе grants | DSL и UI знают multiple | R06: submit/read/update/idempotency работают либо декларация отвергается до UI |
| Relations в Filament | Действия и выбор target проходят собственные проверки | R10: чтение не становится разрешением менять relation; owner/target проверены перед write |
| Widgets и долгоживущая Livewire page | Стандартные panel surfaces покрыты | R11/R12: resource widget охвачен, revoke действует на следующий запрос |
| Автор своего doctor/editor | DoctorCheck, directories и schema опубликованы | R14 и FieldSchema: потребителю не нужно разбирать internal objects или validation strings |
| Обновление внутри major | Manifest и comparator встроены в CI/release | R15: реальные несовместимые примеры отклоняются; regenerated baseline после финальных решений |

### 6.3 Малые API-изменения, которые я бы выбрал сейчас

Это **предложения для следующей реализации**, не описание уже существующих сигнатур. Они требуют осознанно обновить pre-1.0 baseline.

**StorageDescriptor.** Рабочий descriptor содержит разрешённое имя connection, согласованное с authority storage. Исходное значение конфигурации «default» не должно использоваться как поздний указатель I/O. Не публиковать сам mutable `Connection` или весь Internal Storage ради удобства.

**DoctorContext.** Конструктор и служебный wiring скрыты. Потребителю доступны Panel, StorageDescriptor и необходимые поддерживаемые settings/значения. Методы, требующие полного internal config или authority storage, остаются внутри core checks. Добавляется проверка замыкания всех опубликованных типов, включая PHPDoc.

**FieldSchema.** Дать редактору явные `multiple`, `required`, `valueClass`/model identity и storage semantics. Сейчас Filament выводит cardinality из строк validation rules; внешний editor вынужден воспроизводить такой parser. Validation rules должны быть представлением правил, а не единственным источником структуры поля. Codec из R06 становится общим для записи, чтения, fingerprint и decision fields.

**PermissionManager.** Привести create/update к одному typed описанию permission. Сейчас create не принимает description, update принимает `PermissionDetails`, а публичный `fields` выглядит расширяемым, хотя validator отвергает непустые dynamic-permission fields. Для создания description не должны требоваться две authority mutations. Либо принять existing `PermissionDetails` для create, либо добавить симметричную узкую factory; не обещать пока не реализованную произвольную схему runtime permissions.

**Relation operations Filament.** Вынести mapping read/export/attach/associate/detach в явную таблицу операций. Export остаётся чтением. Изменение связи требует отдельной write ability и проверки обеих сторон; генерируемые enums/catalog включают её. Это семантический выбор, который заметно дешевле сделать до 1.0.

**Имена source.** У нескольких RelationSource одного scope type сейчас конфликтует id; существующий тест это намеренно фиксирует. Добавлять `id:` стоит только если выбран реальный сценарий нескольких relation sources на одной панели. Это необязательная design-развилка, не скрытый blocker; подробности в [авторизации](parts/authorization.md).

## 7. Внутренний рефакторинг: что именно менять

### 7.1 Общие входы и authority, отдельные способы исполнения

Целевая структура показана в [схеме решений](diagrams/decision-parity.svg). Она описывает **предложенную внутреннюю организацию**, а не новый public API.

Выделить небольшие внутренние функции/значения для нормализации subject/context, structural resolution, qualified contributions и authority inputs. Scalar pipeline, batch coordinator, Visibility и directories используют их там, где совпадает семантика. Сохранять различия исполнения: SQL должен фильтровать до pagination, batch читает один snapshot на physical PDO, scalar принимает конкретный resource.

Не нужно сразу строить универсальный AST всего authorization engine. Сначала исправить контрпримеры и извлечь только реально повторённые правила. В частности, policy/restriction, зависящая от полного набора matching grants, требует либо точного row-dependent представления этого набора, либо явного unsupported. Случай нескольких grants, один из которых restricted, должен быть выбран и закреплён в контракте, а не определяться порядком веток OR.

### 7.2 Validated значения на границе расширений

Ownership table id, options retention и field representation проверяются в своём владельце до I/O. Добавление общей validation registry поверх всей системы даст лишние extension points и не устранит конкретные дефекты. Нужны одни и те же проверенные значения в compiler, writer и module consumers.

Для identity ключей использовать типосохраняющее кодирование в уже существующем `IdentityCodec` либо узком внутреннем helper. Нельзя превращать неподдерживаемый JSON scalar в произвольный `context_filter_error`, если scalar-путь принимает такое же поле. Текущий домен contribution fields — scalar/null/array; его не нужно расширять объектами ради исправления codec. Единообразно определить non-UTF8 strings, числовые крайние случаи и порядок map; enum/model field уже на своей границе должен иметь допустимое plain representation.

### 7.3 Поддерживаемая поверхность отделена от wiring

Проход R14 ограничен реальными утечками. Не нужно публиковать все внутренние классы или переименовывать весь namespace. Конструкторы service-классов обычно принадлежат контейнеру; data objects и DSL, которые действительно строит пользователь, остаются поддерживаемыми с named arguments. Stable return type должен вести к stable usable members.

### 7.4 Защита интерфейса следует операции

Один актуальный guard нужен на mount, последующий Livewire request, выполнение action и чтение чувствительных данных страницы. Hidden button не заменяет guard исполнения. Существующие guards bulk/export и optimistic write checks сохранить; расширить catalog/enforcement на resource widgets, а ability mapping на операции связи.

R13 устраняется локальной дедупликацией directory descriptions в одном request. Ключ обязан учитывать panel, tenant, actor, directory, lookup phase и subject type/id; проще держать memoization внутри уже фиксированного lookup context страницы. Shared cross-request cache такого UI metadata перед freeze не нужен: он добавит новую проблему инвалидирования.

## 8. Граница последнего рефакторинга

**Обязательно завершить:** R01–R08, R10–R12, R14–R17. R09 лучше исправить вместе со structural resolution, R18 — вместе с генераторами: это небольшие дыры в готовых consumer-сценариях. R13 — локальная оптимизация, которую допустимо перенести, если её исправление разрастается.

**Выбрать до baseline:** semantics multiple columns; физический storage descriptor; relation write abilities; public DoctorContext; симметрия PermissionDetails и машинная структура FieldSchema. У этих решений есть последствия для поддерживаемых сигнатур/форматов. Проверить существующих consumers перед выбором, не объявлять желаемые прототипы уже реализованными.

**Не включать без нового измеренного основания:** физическое выделение всех модулей, новая plugin workflow engine, general writer SPI, переименование всего guard/panel словаря, замена Eloquent/Illuminate, переписывание DatabaseSource только из-за 1 365 строк, event sourcing или гарантированная доставка внешних сообщений. After-commit events не равны durable outbox; если приложению нужна такая доставка, существующий transactional participant — правильное место для отдельного решения.

**Не менять в рамках косметики:** schema-2 meanings, migration basenames, morph/role keys, tenant/scope partitions, scope mode algebra, source-error fail-closed, primary/replica freshness policy. Их изменение несёт больше риска, чем найденные адресные исправления.

## 9. Итоговое условие freeze

Подробная последовательность приведена в [IMPLEMENTATION.md](IMPLEMENTATION.md). Работу считать завершённой, когда:

1. Каждому runtime-контрпримеру соответствует штатный regression test, проверяющий исправленное поведение; probe, который демонстрирует дефект, не засчитывается как acceptance.
2. Scalar/batch/query/directory parity проверена на нескольких contributions, пользовательских resolvers и переданном subject; unsupported возвращается явно и атомарно.
3. Участник не может получить authority-table capability; descriptor и Audit работают с одним storage; multiple field проходит полный roundtrip либо отвергается при декларации.
4. Filament relation mutation требует права записи; widgets учтены; revoke действует на последующий Livewire request.
5. API/SPI замкнут, generator/comparator ловят четыре отрицательные BC-пробы, snapshot/manifest получены их генератором и прошли `--check`.
6. Обязательные checks зелёные в воспроизводимом toolchain; предупреждения/потерянные workers не превращаются в 100% coverage. Пройден отдельный Laravel 11 × PostgreSQL regression из P4.9.
7. Evidence ссылаются на конкретный итоговый SHA. Baseline для сравнения, SHA квалификации и SHA предполагаемого release названы отдельно; после их фиксации отсутствует непроверенный code/validation diff.

Результат такого прохода будет выглядеть как небольшой, последовательно используемый authorization toolkit: короткий Eloquent API, точный explicit API для сложных случаев и ограниченный SPI для расширений. Нынешний репозиторий уже близок к этой форме. Оставшаяся работа — доказать согласованность границ, которые 1.0 обещает поддерживать.
