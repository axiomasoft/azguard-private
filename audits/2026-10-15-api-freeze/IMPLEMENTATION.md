# Задание следующей модели: завершить API перед 1.0

Основание: [аудит](REPORT.md) к `2ce692f74c210e81426e9367303701516b1cc3f0`, 2026-10-15. Это предлагаемая последовательность реализации, **не уже исполненный plan item и не разрешение на выпуск**. Последний завершённый master plan: `2026.10.10-№1-AZGUARD-CORE-STABILIZATION`. Его D7/D8/D10/D19/D24/D25/D26/D27 сохраняются. Следующий исполнитель сначала читает актуальный HEAD, применимые project instructions и проверяет рабочее дерево: findings относятся к указанному commit, пользователь мог уже исправить часть из них.

Цель: закрыть подтверждённые контрпримеры, принять несколько ограниченных решений о публичном контракте и квалифицировать конкретный новый кандидат. Не превращать работу в новую архитектурную миграцию. В этом аудите production, штатные tests и plan не редактировались.

## 1. Порядок и владение

| Шаг | Findings | Основной владелец кода | Зависимости и результат |
|---|---|---|---|
| W1 — точность authorization | R01/R02/R07/R08/R09 | core Authorization, SubjectAccess; scopes directory | Первым закрыть три fail-open; затем общий input/resolution contract |
| W2 — безопасные write values | R03/R04/R05/R06 | PanelCompiler, Storage, Fields, Changes; Audit | Может идти независимо от W1; field codec согласовать с W1, descriptor — с W4 |
| W3 — корректные действия UI | R10/R11/R12/R13/R18 | Filament guards, keys, editors, console | После выбора relation abilities; не ждать W4 для локальных guards/generator |
| W4 — закончить supported surface | R14 и предложения §6.3 | Diagnostics, contracts/fields, PermissionManager, publication markers | Использует descriptor/field semantics W2; даёт финальные сигнатуры |
| W5 — защищать обещанный API | R15 | bin/api-manifest.php, bin/api-compat.php, command snapshots, arch tests | Негативные probes можно делать сразу; финальная генерация после W1–W4 |
| W6 — checks и onboarding | R17, документация | PHPStan/Rector/coverage config, fixtures, guides | Настроить рано; обновить примеры после выбора API |
| W7 — новый кандидат | R16 | qualification/evidence/handoff | После W1–W6; никаких непроверенных code/validation изменений после выбранного SHA |

При нескольких исполнителях назначить владельцев файлов; W1/W2 согласуют домен значений и переиспользуют одинаковые primitives, сохраняя разные задачи identity и persistence codecs; W2/W4 используют один StorageDescriptor. Существующие изменения других исполнителей сохранять. Отдельные коммиты по смыслу с русскими Conventional Commit messages; baseline не обновлять между каждым небольшим исправлением только ради зелёного comparator.

## 2. W1: один смысл разных способов проверки

Исходные места и точные сценарии: [authorization.md](parts/authorization.md). Перенести контрпримеры из [repro.php](evidence/authorization/repro.php) в штатные behavioral tests с утверждениями **исправленного** поведения. Текущие audit probes нарочно утверждают/показывают дефект и не подходят как зелёная приёмка.

### W1.1 — batch identity: R08

Начать с `Authorization/Internal/BatchInputs::contributionParts`. Выбранное направление: сохранить нынешний plain-value домен Kernel и строить внутренний типосохраняющий ключ; ограничивать accepted values во всём API только по отдельному доказанному основанию. Codec должен учитывать тип и границы каждого значения: int/float/string/bool/null, nested arrays и ключи. Binary strings нельзя прогонять через UTF-8 replacement. Не вводить `unserialize` недоверенного содержимого: речь о вычислении ключа в памяти, не о новом persisted format.

`JSON_PRESERVE_ZERO_FRACTION` закрывает пример `1`/`1.0`, но не решает весь домен. Сначала определить поведение INF/NaN/negative zero в Kernel, затем убедиться, что одинаковый cache key означает неразличимые для evaluator values. Случаи, которые невозможно безопасно разделять, можно не дедуплицировать. Не менять внешние grant fingerprints или schema-2 format из-за внутреннего batch key.

**Приёмка:** `[1,1.0]` и обратный порядок, nested variants, разный verdict evaluator; scalar и batch согласованы. Binary string/Unicode/INF/NaN принимаются или явно отклоняются одинаково в factories и execution paths. Grant и RoleContribution покрыты. Перестановка batch не изменяет результат запроса.

### W1.2 — structural resolve: R02/R09

`ResourceContextMapping::membership` не должен считать `tenantOwner=false` доказательством существования/допустимости scope. Сохранить быстрый путь только для declaration, гарантирующей `resolvesThroughQuery`; custom resolve проходит существующую bounded verification либо получает явный unsupported. Один внутренний verifier проверяет ref, record identity, tenant и failure semantics. Применить его в directory search, сохраняя смысл limit: запрещённые первые строки не должны незаметно лишать пользователя всех допустимых options.

**Приёмка:** global tenant + no owner + resolve(null); wrong ref/model/key/tenant; exception; direct и role grants; scalar/batch/SQL. При том же LookupContext и неизменном состоянии `search` не предлагает option, который `describe`/write structural validation отвергает. Проверить лимит и ограниченность запросов. Не ослаблять final write validation даже при исправленном справочнике: revoke/stale selection после поиска по-прежнему закрывает запись.

### W1.3 — row-dependent authority в SQL: R01

Минимальный безопасный контракт: query adapter не получает ложный уже квалифицированный context. Для `matchingGrants()`/других row-dependent inputs дать точное представление, если оно уже вычислимо, иначе явно бросить `VisibilityNotSupportedException` до изменения исходного Builder. Предпочтение перед freeze — честный unsupported в неподдерживаемом случае; новый универсальный expression language не нужен.

Если нужна полная поддержка, сначала описать семантику полного набора matching contributions для конкретной строки, затем реализовать SQL representation. `withAuthority([$grant], ...)` внутри OR-ветки недостаточен: restriction может запрещать весь набор, если присутствует хотя бы один другой restricted grant. Общий helper scalar/query из audit probe должен получать эквивалентные входы, а не фальшивый `[]`.

**Приёмка:** один restricted grant; safe+restricted вместе; condition на ресурс; `count(matchingGrants()) >= 2`; policy и restriction. SQL равен scalar/batch или явно unsupported. Проверить count/pagination/bulk на поддержанных случаях, исходный Builder не изменён при отказе. Не фильтровать после pagination и не трактовать unsupported как pass.

### W1.4 — caller-held model: R07

Выбранный контракт: wrapper, построенный из validated Model, использует этот экземпляр при квалификации automatic roles во всех helpers. Bare SubjectRef продолжает разрешаться через источник. Передать held model через общий preparation input в permissionSet/roles/superadmin и проверить PanelAdmission. Не смешивать это с policies/restrictions: `permissionSet()` по-прежнему не является полной policy-проверкой.

**Приёмка:** held true/DB false и наоборот; eagerly loaded relation; bare ref fresh read; одинаковая квалификация automatic role для decide/hasRole/isSuperAdmin/roleNames/permissionSet. Не добавлять скрытый refresh модели вызывающего приложения.

### Граница рефакторинга W1

Извлечь только общие нормализацию, structural verification и qualification inputs, которые уже нужны нескольким путям. Сохранить scalar stages, batch snapshot coordination и SQL backend отдельными. Не публиковать новые internal objects в API. [Схема](diagrams/decision-parity.svg) показывает разделение общей семантики и способов исполнения; она не требует единого нового мегасервиса.

## 3. W2: значения и capabilities на пути записи

Подробные источники: [storage-extensions.md](parts/storage-extensions.md), [reproduce.php](evidence/storage/reproduce.php).

**R03, table ownership.** В PanelCompiler принимать только каноническое lowercase ASCII base name с однозначной grammar. Reject uppercase, квалифицированные `schema.table`, quotes, whitespace/aliases и зарезервированные core names; не делать скрытый lowercase после проверки denylist. Проверять duplicate ownership в той же нормальной форме. OperationRows получает уже проверенный id и использует только его с prefix. Это capability для доверенного PHP plugin, а не sandbox произвольного кода; raw DB access приложения этим не запрещается.

Приёмка: текущий `ROLE_GRANTS` probe больше не компилируется; запрещённые варианты core tables/duplicate participants; разрешённая собственная таблица работает. Неуспешный participant целиком откатывает authority rows, собственные rows, revisions и события. SQLite плюс серверные engines; не выводить переносимость идентификаторов только из SQLite.

**R04, StorageDescriptor.** В выбранном контракте descriptor хранит effective connection name, соответствующее уже закреплённому storage. Исходный `null` означает выбор default **при создании**, не при каждом последующем I/O. Проверить AuditSchema, JournalPruner и identity-column code, в том числе панель с private storage через `StorageRegistry::own()`. Audit остаётся в той же транзакции и storage панели; отдельный Audit connection API не добавлять. Переключение default после boot не должно переключать target. Миграции читают тот же descriptor; mutable Connection не публиковать.

Приёмка: два независимых SQLite memory connections; сменить default после boot, выполнить реальную команду prune. Изменяется только заявленная БД, counts второй неизменны. Повторить для панели с private storage, custom prefix и migrate/rollback; Audit использует storage этой панели. Если меняется сигнатура/nullable type descriptor — явно отразить в pre-1.0 API решении и generator.

**R05, AuditOptions.** Retention >=1 проверять в public constructor, чтобы `new`, builder и provider override были равноценны. Перед destructive command полезно иметь invariant check, а не полагаться на происхождение options. Приёмка: отрицательное/нулевое значение отклоняется до delete; валидное хранится; cutoff/timezone/boundary и fake-clock тесты не меняют смысл.

**R06, multiple columns.** Рекомендуемый законченный вариант: list field хранится в объявленной JSON/text-compatible column через двусторонний codec; scalar values/meta и lists используют общие правила валидации и представления. Соблюдать поддерживаемую карту драйверов. Codec нужен не только insert, но и update/read/projection/fingerprint. Явно определить null vs empty list, enum backing value и ordering. Заодно отдать cardinality редактору через FieldSchema, а не через parser validation strings.

Если ограничение объёма не позволяет безопасно закончить roundtrip, допустим меньший **явный** контракт: compile-time запретить `multiple()` вне meta и документировать его. Не оставлять вариант, где UI обещает список, а save падает QueryException. Не засчитывать casts на model как решение, пока writer идёт через query builder и их не вызывает.

Приёмка: insert/read/update/decision/idempotent repeat/fingerprint для list/null/empty/enum на четырёх драйверах; malformed input даёт ожидаемую validation error без частичных записей. W1 identity codec и W2 persistence codec имеют разные задачи, но согласованный домен значений; не связывать их одним случайным JSON вызовом.

## 4. W3: Filament как реальный UI, а не набор скрытых кнопок

Источники: [filament-laravel.md](parts/filament-laravel.md), [Livewire probes](evidence/filament/FilamentProbeTest.php).

**R10, relation operations — выбранное направление API.** Отдельные relation write abilities (`attach`, `associate`, `detach`, `dissociate`) с явным mapping; чтение selectable related rows остаётся дополнительной проверкой. Определить owner/target contract до написания кода: минимум операция проверяется на owner record, выбранный related record должен быть видим/допустим; для host policy, зависящей от обоих, передаётся доступный и документированный контекст. Не выдумывать автоматически подходящий resource для existing policy. Явный `authorize(...)` приложения может ужесточить проверку. Export остаётся read operation.

Приёмка: только view grants → action скрыт/disabled и forged Livewire вызов запрещён, pivot не меняется; write grant без доступа к owner/related record не проходит; корректный write меняет relation. Attach/associate/detach/dissociate, bulk counterparts, revoke между mount и submit. Catalog/enum generator и guide описывают один mapping. Существующий тест, закрепляющий read→attach, заменить тестом принятого контракта, а не объявлять регрессию случайностью.

**R11, widgets.** Один inventory для enforcement, FilamentSource, enum generation и doctor: panel widgets + widgets всех зарегистрированных resources/configurations. Дедупликация class/config identity, существующие excludes. Для динамического header/footer widget либо явное declaration, либо runtime fail-closed integration; не обещать автоматический анализ произвольного PHP. Не инстанцировать все страницы с побочными I/O ради discovery.

Приёмка: обычный resource widget без trait/exclude отвергается; с trait появляется в keys, скрыт без grants и виден с grants; render actual page; configuration-only resource/WidgetConfiguration; одно объявление в двух местах не ломает каталог; exclude сохраняет native semantics.

**R12, ViewRole.** Проверять detail ability при каждом серверном чтении/рендере, в том числе hydrate. Приёмка: открыли → revoke view → refresh403 без detail payload; restore → доступ; revoke view_any; неизвестная role404; target tampering по-прежнему закрыт.

**R18, генератор.** В EnumMarks использовать FQCN либо детерминированные уникальные aliases, учитывая существующие imports. Итоговый PHP валидировать до замены consumer file. Приёмка: полный CLI для двух одноимённых widgets с разными permission keys; php-lint и ReflectionEnum подтверждают оба правильных FQCN; collision с attribute/model/enum import; повторный запуск/force. Audit artifact с duplicate imports является намеренно невалидным доказательством, не production fixture для lint.

**R13, query cost.** Небольшой request-local memoization описаний в одном неизменном lookup context. Учитывать actor, panel, tenant, directory, phase и subject ref; сбрасывать при изменении context/write. Приёмка: та же страница 25 grants, один subject/actor — один либо явно небольшой фиксированный budget lookup; чужой context не переиспользует labels; orphan отображается как raw identity. Не добавлять global cache и публичный batch directory SPI только ради этого примера. Допустимо перенести оптимизацию, если она начинает требовать новую архитектуру.

## 5. W4/W5: поддерживаемая поверхность и её защита

Полный перечень leakage и четыре negative cases: [plan-delivery.md](parts/plan-delivery.md).

**W4.** По 10 native methods и PHPDoc leaks пройти точечно. Wiring constructors и compiler helpers отметить internal. DoctorContext возвращает поддерживаемые observations/StorageDescriptor; core checks получают internal services своим wiring. Для `AzGuardPlugin::keys` выбрать internal либо отдельное stable read view; не публиковать весь FilamentKeys. FakeSubject может возвращать поддерживаемый base Builder. Добавить external DoctorCheck fixture, использующий только supported symbols. Arch test проверяет транзитивное замыкание native/PHPDoc types, aliases и generic arguments с явной allowlist framework types; не запрещает всякий `Internal` строковым поиском комментариев.

Две ограниченные DX доработки разумно включить до baseline: симметричное создание permission с description через PermissionDetails, и явные FieldSchema cardinality/value metadata. Окончательную сигнатуру выбирать по текущим call sites и named-argument compatibility. Не включать runtime-permission custom fields как новую подсистему: сейчас публичный fields аргумент не соответствует реализованной возможности. Несколько RelationSource одного scope type оставить отдельным необязательным решением по реальному consumer-сценарию.

**W5.** Generator сохраняет return-by-reference, used/imported PHPDoc aliases и полную структуру console InputDefinition. Comparator учитывает implement/call variance, readonly removal у extensible classes и properties, array/value modes CLI. Synopsis остаётся человекочитаемым представлением, а не единственным машинным контрактом.

Приёмка: все четыре cases из [compatibility-blind-spots.php](evidence/delivery/compatibility-blind-spots.php) возвращают breaks; реальные consumer snippets/CLI демонстрируют, почему. Положительные unchanged/additive controls остаются зелёными. Никаких ручных исправлений generated JSON: owning commands → check. Не добавлять Roave/runtime dependencies вопреки D26 без новой причины.

## 6. W6: воспроизводимые checks и честный quick start

1. PHPStan должен действительно анализировать public `InteractsWithAzGuard`: маленький representative consumer analysis fixture или иной принятый trait-use path. Не просто игнорировать `trait.unused`, чтобы скрыть неанализируемый supported API.
2. Rector config согласовать с поддерживаемым диапазоном версии. Установленный 2.6.7 не содержит исключаемый `TernaryToNullsafeCoalesceRector`; команда должна работать в reproducible install. Не предполагать, что любой новый Rector обязан содержать старое имя.
3. Type coverage обязан проваливаться при потерянных worker results и проверять полноту списка файлов. Обычная команда в этом окружении объявила 100% по одному файлу с warnings; контроль без parallel/PAO показал 99.9%. Это не доказательство исправности исходной команды. Не подавлять warnings ради green.
4. Quick start должен создавать или явно ссылаться на Post model/table/controller, а archive fixture README описывать уже реализованные проверки. Убрать устаревшее обещание public Storage::mutate из consistency guide; вместо него описать public change API/participant.
5. Сохранить fresh-install 1.0 scope D19/D24/D25. Не возвращать 0.7 upgrade qualification и историческое performance comparison в новый gate. Инструкции окружения swissknifeman, попавшие в azguard AGENTS.md, исправлять через owning generator отдельно, не переписывать вручную generated projection.

## 7. W7: что считать завершением

После локальных regression suites провести принятые root checks в закреплённом toolchain, archive-consumer core-only/core+Filament, module boundaries, docs, API freshness/compatibility и database qualification. Таблица [VALIDATION.md](VALIDATION.md) сообщает, что проверено **в аудите**, но не заменяет проверки изменённой реализации.

Отдельно закрыть Laravel 11 × PostgreSQL `ContractWorld::rowCounts` из P4.9: текущая обычная server matrix начинается с Laravel12. Не выдавать Linux/PHP8.4/Laravel13 audit за полный PHP×Laravel matrix. Полный runtime Octane и replica scenarios остаются явно указанными границами проверки; выполнить требуемые release lanes согласно текущему CI/плану, не считать их зелёными по косвенным тестам.

Выбрать и записать отдельно: SHA исходного comparison baseline; SHA нового проверенного candidate; SHA предполагаемого release. После final code commit запускать qualification на этом commit и ссылаться на его результаты. Handoff исправляет старое «после C+1 только plan files», не меняя исторические logs. Generated contracts сначала регенерируются и проверяются; deliberate pre-1.0 breaks перечисляются явно.

Для независимого итогового review предоставить diff, новую матрицу regression cases и результаты checks. Exit0 audit probe с воспроизведением ошибки не означает её закрытие. Обязательные findings закрываются кодом и тестами либо явным уменьшением обещанного API до freeze; перенос R13 и необязательных DX предложений должен быть назван. Tag/split/publish не выполнять по этому документу: D27 оставляет выпуск владельцу.

Ожидаемый итог — тот же удобный toolkit с коротким Eloquent API, точным explicit API и ограниченным SPI. Изменение должно объясняться найденными consumer-сценариями; новая абстракция без такого сценария в этот последний проход не входит.
