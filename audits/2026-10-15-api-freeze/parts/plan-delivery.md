# План, API freeze и поставка AzGuard

Дата: 2026-10-15. Исходный HEAD: `2ce692f7`. Проверка read-only; изменены только файлы этого аудита. Область: последний stabilization plan и решения D15–D27, publication markers/manifests/comparator, Composer metadata, release/CI graph, consumer fixture и пользовательская документация. Полные прогоны выполняет основной агент; ниже отдельно названы собственные наблюдения и отрицательные пробы.

**До окончательного freeze нужны два изменения конструкции API и завершение квалификации нового кандидата.** Сама выбранная схема поставки жизнеспособна: один core-пакет с тремя внутренними модулями, отдельный Filament-пакет, явный API/SPI и один источник данных для facade/manifest. Переразбиение Composer-пакетов и широкая смена имён для устранения найденных проблем не нужны.

## DD-01 — опубликованная поверхность не замкнута: SPI возвращает Internal-типы

**Severity: Major. Confidence: high.** Текущая проблема границы контракта; падение обычной проверки доступа этим не утверждается.

Основные места:

- `packages/core/src/Diagnostics/DoctorContext.php:38` — публичный `storages(): array` обещает `list<Storage>`, где импорт `Storage` на строке 11 — `AzGuard\Storage\Internal\Storage`.
- `packages/core/src/Diagnostics/DoctorContext.php:50` — опубликованный `config()` возвращает `AzGuard\Configuration\Internal\AzGuardConfig`.
- `packages/core/src/Contracts/Diagnostics/DoctorCheck.php:28` — реализуемое приложением расширение получает именно этот `DoctorContext`.
- `packages/filament/src/AzGuardPlugin.php:377` — опубликованный `keys()` возвращает внутренний `FilamentKeys`.
- `tests/Arch/ApiManifestTest.php:260` — I4 проверяет потребление core API модулями/Filament/stubs, но не замыкание типов всей опубликованной поверхности.

**Триггер и влияние.** Автор собственного `DoctorCheck` использует публичный `context->config()` или перебирает `context->storages()`. Полученный объект и его методы исключены из compatibility promise (`docs/reference/compatibility.md:12`, `:20`). Получается обещание стабильного входа в нестабильный объект; manifest фиксирует имя возвращаемого класса, но не контракт его методов. В частности, внутренних проверок касается реальное использование `config()->configuredBuildId()` и `storages()[…]->verifySchema()`. Потребитель не может опираться на аналогичный код как на поддерживаемый SPI.

Это не только тема конструкторов. В текущих манифестах найдены **10 опубликованных методов в 8 типах** с native-параметрами/return types из `Internal`; ещё есть PHPDoc-утечка `DoctorContext::storages()` и внутренний интерфейс `FolderSource implements EvaluatesAutomaticRoles`.

| Тип/член | Место | Что попадает в обещание |
|---|---|---|
| `Visibility::__construct` | `packages/core/src/Authorization/Visibility.php:64` | registry, subject resolver, current context |
| `DoctorContext::__construct`, `config`, `storages` | `packages/core/src/Diagnostics/DoctorContext.php:24` | внутренние config и storage objects |
| `PanelOverview::__construct` | `packages/core/src/Diagnostics/PanelOverview.php:29` | PanelRegistry |
| `GateBridge::__construct` | `packages/core/src/Laravel/Gate/GateBridge.php:30` | Authorizer, PanelResolver, PanelRegistry |
| `FolderSource::configured`, `bind` | `packages/core/src/Sources/Folder/FolderSource.php:141`, `:170` | PanelRecipe, DiscoverySnapshot |
| `StorageSchema::__construct` | `packages/core/src/Storage/Schema/StorageSchema.php:21` | StorageRegistry |
| `FakeSubject::newEloquentBuilder` | `packages/core/src/Testing/FakeSubject.php:47` | FakeSubjectBuilder |
| `AzGuardPlugin::keys` | `packages/filament/src/AzGuardPlugin.php:377` | FilamentKeys |

**Исправление перед freeze.** Провести один небольшой проход по этой границе. Закрыть `@internal` те constructors/compiler wiring/helpers, которые приложение не должно вызывать. Для действительно полезных данных `DoctorContext` оставить узкие поддерживаемые наблюдения: существующий `StorageDescriptor`, нужные скалярные параметры/значения конфигурации; внутренним core-checks передавать необходимые службы через собственные зависимости. `FakeSubject` может декларировать поддерживаемый базовый Eloquent Builder, сохраняя внутреннюю реализацию. `keys()` либо сделать внутренним, либо определить намеренно поддерживаемое представление ключей. Не публиковать весь `Internal\Storage`/`AzGuardConfig` ради прохождения сканера.

**Acceptance.** Проверка замыкания опубликованных native types и PHPDoc generics/type aliases; отрицательные fixtures на native-return и `list<InternalType>`. Конструкторы и helpers, исключённые из обещания, отсутствуют в regenerated manifest. Небольшой внешний `DoctorCheck` должен получать необходимые данные, не импортируя и не вызывая Internal-типы. API-manifest freshness и module I4 остаются зелёными.

**Привязка к плану:** D9 и D15; P2.1/publication и P2.15 review; I4; P4.2/P4.8 gate condition 3. Нарушение относится к заявлению «supported surface closed», а не к количеству публичных классов.

Доказательство инвентаризации: `audits/2026-10-15-api-freeze/evidence/delivery/supported-surface.json`.

## DD-02 — compatibility gate пропускает четыре воспроизведённых изменения контракта

**Severity: Major. Confidence: high.** Это дефект механизма freeze, а не утверждение, что перечисленные изменения уже внесены в production API.

**Места:** `bin/api-manifest.php:230` (сигнатура метода), `:263` (PHPDoc), `bin/api-compat.php:368` (флаги класса), `:917`/`:934` (CLI synopsis), `tests/Feature/Console/CommandsSnapshotTest.php:36` (снимок только synopsis).

Выполнена ограниченная read-only проба: загружены настоящие функции генератора и comparator, synthetic before/after декларации описаны через Reflection; для `readonly` использована копия настоящей записи `AccessEvent` в памяти. Production-файлы не менялись. Для CLI использован установленный Symfony InputDefinition/ArgvInput. Результаты:

| Изменение | Почему gate его теряет | Наблюдение |
|---|---|---|
| `function roles(): iterable` → `function &roles(): iterable` у Implement-интерфейса | generator не сохраняет `ReflectionMethod::returnsReference()` | manifest before/after идентичен, `breaks=[]`; существующий implementer загружается до изменения и падает PHP exit 255 после него |
| Снятие `readonly` с расширяемого `AccessEvent` | comparator запрещает только false→true; true→false для класса и properties проходит | запись настоящего `AzGuard\Events\AccessEvent` даёт `breaks=[]`; readonly consumer subclass падает exit 255 при снятии readonly с базы |
| `{--case=*}` → `{--case=}` | Symfony synopsis не содержит array-mode опции, snapshot/comparator его не знают | оба synopsis `[--case [CASE]]`, `breaks=[]`; две опции превращаются из списка двух cases в последнее строковое значение |
| Изменение `@phpstan-type Entry` при неизменном `@return list<Entry>` | `apiDocContracts()` отбрасывает определения type aliases | одинаковый manifest, `breaks=[]`, при пробе переименованного поля consumer больше не получает ключ `id` |

Последние два случая уже имеют прямые аналоги в текущей поверхности:

- `packages/core/modules/devtools/src/Internal/Console/Make/MakePermissionCommand.php:35` содержит **реальную повторяемую** `--case=*`; на строке 120 она читается как массив. Записанное в P4-review утверждение «no AzGuard command has an array option today» не соответствует коду.
- `packages/core/src/Diagnostics/PanelOverview.php:23` и `:24` определяют `Sources`/`Entry`, а `all()` на строке 37 обещает `list<Entry>`. Изменение определения `Entry` не меняет записанную строку `@return list<Entry>`.

**Влияние.** Успешный `api:check` доказывает соответствие неполному представлению. Затем `api:compat` успешно сравнивает это представление с baseline. Поэтому зелёные проверки пока недостаточны для утверждения «каждая поддерживаемая сигнатура/форма CLI защищена от нарушения совместимости». Как минимум SPI implementer может перестать загружаться, а CLI consumer молча потеряет ранее переданные values.

**Исправление перед freeze.** Добавить return-by-reference в manifest и правила сравнения. Защитить обратное изменение readonly для расширяемых типов и закрепить правило для property overrides. Снимать структуру `InputDefinition` напрямую, включая `isArray`, value mode, shortcut/negatable/default и position аргументов; synopsis оставить отображением. Записывать определения и импорты PHPDoc aliases, задействованных публичными signatures, с разрешением имён; буквальный `list<Entry>` не описывает весь контракт. Все файлы снимков регенерировать через владельца, затем осознанно зафиксировать новый candidate baseline.

**Acceptance.** Четыре negative cases должны давать непустой break list; positive controls без изменений и с разрешёнными additions остаются зелёными. Добавить реальные mutation/drill cases в throwaway tree: reference return на Implement-интерфейсе и array-mode опции. Проверить, что regenerated command snapshot замечает `--case=*`→`--case=`, а изменение `PanelOverview` alias меняет manifest. Проверка PHP consumer должна демонстрировать ожидаемую причину rejection, а не только сравнивать две вручную собранные JSON-структуры.

**Привязка к плану:** D9/D15/D26, P4.3 и P4.5, I18; P4.7 command/PHPDoc promise; gate condition 10. Это дополнение доказанно неполного представления контрактов, а не предложение сменить выбранный собственный comparator на новую зависимость.

Воспроизведение:

```bash
php audits/2026-10-15-api-freeze/evidence/delivery/compatibility-blind-spots.php
```

Результат сохранён в `audits/2026-10-15-api-freeze/evidence/delivery/compatibility-blind-spots.json`.

## DD-03 — статус плана done не закрывает квалификацию текущего HEAD

**Severity: Major для утверждения готовности текущего HEAD к freeze. Confidence: high.** Старые проверки действительно выполнены; их результаты не объявляются ложными. Релиз в данном аудите не выполнялся.

**Места:** `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/evidence/P4/gate.md:24`; `…/artifacts/reviews/P4-review.md:11`; `…/phases/P4/P4.9.json` result; `compatibility.json:3`; `.github/workflows/tests.yml:79`.

План имеет статус `done`, но его финальный review явно оставил выбор и проверки открытыми:

- Frozen C = `3d789538c65710ff77cc13bfc3109312b7f9e455`, pointer commit C+1 = `5ec7a7948c2099db249f3c0349eb4f25949f74ba`.
- На C reviewer нашёл R1: Laravel 11 × PostgreSQL в `ContractWorld::rowCounts()` учитывал чужие schemas. Исправление `2360df2b` находится после C+1; review разрешал только `php -l` и Pint, что честно указано в отчёте.
- `gate.md:24` сообщает, что после C+1 менялись только plan files. Для текущего HEAD это неверно: отличаются **11 non-plan files**, включая `packages/core/src/Testing/Internal/Contracts/ContractWorld.php`, parity runner, CI и PHPStan/test fixtures.
- SQLite CI охватывает Laravel 11; DB matrix начинается с Laravel 12 (`tests.yml:79`). Поэтому новая квалификация обычной матрицы сама по себе не закрывает особую комбинацию R1.

**Триггер и влияние.** Использовать `plan.status=done`/«10 green conditions» как доказательство готовности нынешнего HEAD либо выполнить старые tag-команды из P4.8. В первом случае evidence относится к другой версии кода; во втором тег не содержит известное последующее исправление. Compatibility baseline может оставаться старым для сравнения — это отдельная роль от SHA выпускаемого и квалифицированного кандидата.

**Исправление/закрытие.** После последнего санкционированного исправления назначить конкретный SHA кандидата, выполнить положенные checks на нём и отдельно закрыть Laravel 11 × PostgreSQL/contract-kit scenario. Обновить gate/handoff так, чтобы release SHA, evidence SHA и выбранная baseline policy были явно различимы и согласованы. Отразить, какие предыдущие результаты переиспользуются по неизменному code footprint. Выпуск и split/Packagist остаются у владельца по D27.

**Acceptance.** Все evidence rows называют точный кандидат; последующие commits не меняют production или validation footprint без новой проверки. В gate нет заявления «plan-only» при non-plan diff. R1 проверен на Laravel 11 × PostgreSQL. Указанная команда тега направлена на выбранный квалифицированный commit; подготовка команды не является разрешением её выполнить.

**Привязка к плану:** D24/D27, P4.8/P4.9, release conditions 9–10. Отдельный результат аудита основной сессии о текущих PHPStan/Rector/type-coverage checks должен быть включён в новую квалификацию; исторический GREEN этого не заменяет.

## Что фактически замораживается

Текущие committed manifests содержат 265 core-типов (189 Api, 47 Spi Implement, 29 Spi Call) и 22 Filament-типа (21 Api, 1 Spi Implement), 1178 declared поддерживаемых методов суммарно. Это измерение поверхности, не целевой KPI: исключения, value objects, события, contracts/testing support объясняют значительную часть объёма. Уменьшать эти числа само по себе не требуется.

`compatibility.json` покрывает PHP manifests, exceptions/events/audit payloads, resources/publish tags/migration basenames, reserved plugin prefix, config defaults/environment/retired keys, console commands, doctor/explain, serializable FQCN и DDL core/Audit для четырёх драйверов. `Api`/`Spi` задают границу явно, facade генерируется из typed entry class; это полезная основа для freeze. DD-01 и DD-02 касаются полноты этой основы.

Сверка актуальных решений:

| Решения | Наблюдение в проверенной области |
|---|---|
| D15 | Closed/open enums читаются из PHPDoc; генератор требует одну классификацию, comparator проверяет closed-case addition |
| D16–D18 | 0.7 не является compatibility baseline; промежуточные имена не используются для оценки нового API; поведение per-context evaluator относится к runtime-аудиту другого агента |
| D19–D20 | Fresh-install qualification и одна core schema-2 migration; Audit имеет отдельную publish-only migration. Возвращать schema-1 adoption не требуется |
| D21–D22 | Core-neutral scope description и module-owned guard приняты как текущие решения; дополнительная публикация guarded builder не нужна для устранения delivery findings |
| D23–D25 | Публичная история начинается с 1.0; 0.7 инструкция осталась в root UPGRADING, не в docs site; ветки/версии manifests и fixture начинаются с 1.0 |
| D26 | Собственный comparator и baseline guard существуют, wired в CI/release; четыре пробела DD-02 требуют закрытия до доверия автоматическому freeze |
| D27 | План подготовил candidate/review; сам tag/publish не является выполненной частью плана. DD-03 сохраняет эту границу |

## Доставка, CI, документация и consumer workflow

Проверенный release graph содержит blocking dependencies `validate/tests/static/style/docs/nightly/compat` перед split, а GitHub Release дополнительно зависит от split. Split вызывается только reusable workflow и требует явную publishing configuration. Compatibility workflow загружает history/tags, запускает freshness перед comparison; PR guard требует `compat-baseline` для изменения `compatibility.json`. Автоматическое сравнение исключает stable tag, стоящий на самом проверяемом commit, поэтому self-comparison для release не принят.

`consumer-fixture.sh` проверяет архивы в отдельном Laravel application, без path-repository symlink, и имеет core, core+Filament, core-only и relocated-audit режимы. Relocation сохраняет PSR-4 owner, migration basename, publish tag, команды и journal rows. Это сильнее одного monorepo Testbench boot. Повторять выполненный основным агентом consumer run в данном срезе не требовалось. Его актуальные exit codes следует брать из общего evidence, не из этой статической оценки.

Остаётся полезное ограниченное улучшение DX: `docs/getting-started/quick-start.md:3` обещает путь из fresh Laravel, но пример использует не созданные в тексте Post/PostController/posts schema и fixtures. `fixtures/consumer/README.md` всё ещё называет script skeleton и перечисляет запланированные fixtures, хотя HTTP/RefreshDatabase/core-only/relocation уже реализованы. Это небольшая синхронизация документации; она не обосновывает новую архитектуру. Для полностью проверяемого getting-started добавить runnable минимальный Post scenario или явно перечислить domain prerequisites и связать страницу с consumer fixture. Критерий: чистое приложение проходит документированные команды и показанный 200/403 test без знаний из других audit logs.

## Traceability и пределы проверки

- Проверены существование **267** check-evidence references последнего плана с разрешением `@plan/` и SHA-256 **59** логов P4. Missing files и hash mismatches не найдены. Сводка: `evidence/delivery/plan-traceability.json`.
- Исторические nonzero checks имеют явные deviations: например, PHP 8.3 не запускался локально при vendor floor >=8.4 и подтверждался отдельными CI jobs; отсутствие локального zizmor не объявлялось выполненным локальным прогоном. Повторно превращать эти честно закрытые ограничения в текущие дефекты нельзя.
- Прочитаны D15–D27, P4 item summaries/results/check records, amended gate/compatibility contracts, финальный review и текущее отличие от C+1. Не выполнялся полный replay всех исторических логов и не воспроизводилась вся внешняя CI matrix.
- Этот срез не доказывает семантику всех scalar/batch/query/write authorization paths; они переданы другим частям полного аудита. Здесь четыре negative probes проверяют механизм freeze, а не переигрывают полный runtime.

## Практическая последовательность следующей реализации

1. Закрыть DD-01 до обновления baseline: выбрать намеренно поддерживаемые данные на границе DoctorContext и убрать accidental publication constructors/helpers. Добавить тест замыкания API/SPI.
2. Исправить DD-02 как один небольшой набор изменений generator + comparator + snapshots + negative drills. Не менять runtime-разрешения ради detector.
3. Выполнить runtime/verification исправления из остальных частей аудита и синхронизировать getting-started/fixture README. Не вводить параллельный entry point или второй authorization pipeline.
4. Заморозить итоговый кандидат по DD-03: матрица, доказательства, regression для L11/PostgreSQL, потребитель из архивов, comparator/drill. Затем обновить только согласованные freeze artifacts и передать владельцу точную release-команду.

Такой объём завершает выбранную архитектуру. Доказательств необходимости тотального переписывания, разнесения каждого модуля по Composer-пакету или замены собственного comparator новой runtime-зависимостью в этом срезе нет.
