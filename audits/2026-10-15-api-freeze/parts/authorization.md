# Authorization, query parity, sources and directories — аудит 2026-10-15

В этой части подтверждены пять дефектов. **A1, A2 и A4 блокируют принятие кандидата как exact authorization API:** SQL либо batch разрешают доступ, который `decide()` запрещает. A3 требует явного решения о единой семантике модели пользователя до freeze; A5 — ограниченный дефект directory eligibility. Производственный код, тесты проекта и завершённый план не изменялись.

## Воспроизводимость и границы проверки

Рабочий каталог: `/home/vostrikov/projects/packages/azguard`. Прочитаны корневой `AGENTS.md`, оба README, актуальные решения D1–D27, relevant sections `knowledge/contracts`, `knowledge/architecture`, P2.8–P2.10 и P3.1 завершённого плана `2026.10.10-№1-AZGUARD-CORE-STABILIZATION`.

Код просмотрен по цепочкам: `Authorizer`, все pipeline stages, `BatchEvaluation`/`BatchInputs`, authority read/materialization/cache, `Visibility`/`bounded` и SQL compiler/mapping, `EvaluationContext` и связанные SPI, `SubjectAccess`/`SubjectPanels`/`ScopedPanelAccess`, directories, Folder/Gate/Relation sources, DatabaseSource reads/selection/inspection, Kernel identity/grammar/decision/predicate values, Scopes evaluator/eligibility/default directory. Writer mutations и физические гарантии storage остаются в отдельном storage-аудите.

Воспроизведения — отдельный CLI-скрипт [repro.php](../evidence/authorization/repro.php), использующий существующие fixture-модели и runtime-код. Перед autoload он фиксирует `APP_ENV=testing`, SQLite `:memory:` во всех трёх источниках environment; до любых записей проверяет реальное подключение и `PRAGMA database_list` (`file=""`). Сетевые БД не используются. У каждого сценария свежий процесс и свежая БД.

```bash
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php baseline
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php matching-grants
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php structural-resolver
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php given-model
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php batch-fields
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php batch-numbers
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php directory-resolver
```

Все семь процессов завершились с кодом 0; это успешное **воспроизведение**, а не зелёная проверка паритета: значения `*_parity=false` или разные массивы scalar/batch фиксируют дефект. Логи каждого сценария лежат рядом. Начальный дефект stale vendor PSR-4 устранён root-агентом до исполнения; воспроизведения выполнены после подтверждения refresh. Общий test suite выполняет root; эта часть не заявляет отдельный полный зелёный прогон.

## Подтверждённые находки

### A1 — SQL restriction получает пустой `matchingGrants()` и пропускает запрещённые строки

**Severity: Blocker. Confidence: высокая, воспроизведено.**

- Место: `packages/core/src/Authorization/Visibility.php:203` — `withAuthority([], $admin)`; policy adapter получает ещё не квалифицированный frame в том же файле, строка 142. Scalar собирает подходящие grants в `Authorization/Internal/Pipeline/Stages/AuthorityStage.php:123` и передаёт их в `withAuthority()`.
- Контракт: `Contracts/Authorization/EvaluationContext.php:45` предоставляет `matchingGrants()`; `FiltersAccessQueries.php:13` требует exact scalar/query equivalence. Restriction adapter должен кодировать свою applicability, но не обязан предполагать фиктивный пустой набор полномочий.
- Триггер: обычный direct grant содержит decision field `restricted=true`; restriction запрещает запрос, если среди `context->matchingGrants()` есть такой grant. `check()` и `predicate()` вызывают **одну и ту же** чистую функцию над контекстом; predicate возвращает константный pass/deny, без resource-зависимости.
- Результат: scalar и batch запрещают все четыре ресурса с `restricted`, `visibleTo()` возвращает `[101,102,103,104]`. [Лог](../evidence/authorization/matching-grants.log).
- Влияние: списки и пагинация раскрывают данные вопреки действующему restriction. Для adapter автора интерфейс выглядит пригодным для простого точного константного предиката, а движок молча меняет его вход. Это не пример ошибочной SQL-логики адаптера.
- Исправление: отделить контекст SQL-компиляции от уже вычисленного scalar context. Значение, зависящее от квалифицированных grants конкретной строки, должно иметь явную query representation либо приводить к `VisibilityNotSupportedException`, а не подменяться `[]`. Краткий безопасный вариант — запретить чтение row-dependent `matchingGrants()` в policy/restriction query context с понятной причиной unsupported. Полноценная поддержка требует общего внутреннего представления квалифицированных вкладов и их предикатов.
- **Не исправлять простым `withAuthority([$item], ...)`:** scalar restriction видит все подходящие grants, а SQL OR по каждому вкладу позволит безопасной ветке обойти запрет, связанный с другим вкладом.
- Acceptance: один restricted direct grant; смешанные safe/restricted grants; grants с ресурсными conditions; restriction и policy, читающие `matchingGrants()`. SQL должен совпадать с scalar/batch либо явно отказать до изменения исходного Builder. Добавить случай `count(matchingGrants()) >= 2`, чтобы single-grant patch не прошёл.
- Связь с планом: `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.10`, `knowledge/architecture#operation-chains` C/E, обязательный запрет relaxed exact semantics; P4.8 freeze должен переоцениваться после исправления.

### A2 — `tenantOwner=false` отключает произвольный structural `resolve()` в `visibleTo()`

**Severity: Blocker. Confidence: высокая, воспроизведено.**

- Место: `packages/core/src/Authorization/Internal/Query/ResourceContextMapping.php:205` — ранний `P::pass()` до обработки `resolvesThroughQuery` в строке 225.
- Контракт: `Context/AssignmentScopeDescription.php:24` описывает `tenantOwner=false` только как принадлежность всех записей global tenant; отдельный `resolvesThroughQuery=false` говорит, что `resolve()` не равен `query()+tenantOf()`. Эти независимые свойства нельзя сворачивать в «структурных ограничений нет».
- Триггер: queryable scope без tenants честно описывает `tenantOwner:false, resolvesThroughQuery:false`; `query()` видит проекты 1–4, `resolve(project:1)` возвращает null, остальные разрешает. Это обычный custom resolver для отключённого/архивного/внешне недоступного scope. Query adapter у definition отсутствует.
- Результат: scalar/batch запрещают ресурс 101 (`context_not_accepted`), разрешают `[102,103,104]`; `visibleTo()` включает 101 и возвращает все четыре записи. [Лог](../evidence/authorization/structural-resolver.log).
- Исправление: no-owner fast path допустим только при доказанной `resolvesThroughQuery` семантике. Custom resolve должен пройти существующую bounded structural verification независимо от tenantOwner, либо SQL должен явно отказаться. Переиспользовать одну проверку ref/tenant/model/key между scalar, batch, query и directory.
- Acceptance: глобальный tenant + custom resolve с null, foreign ref, wrong record/key, exception. Проверить и direct, и role grant; `visibleTo()` не может включать отказанный scope. Сохранить быстрый путь стандартного `ModelAssignmentScopeDefinition` без owner callback.
- Связь с планом: `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P3.1` (D21, независимые description facts), `P2.8` structural verification, `P2.10` exact lists. Существующий `tests/Feature/Visibility/ScopeResolverTest.php:72` проверяет custom resolver с default `tenantOwner=true`, поэтому эту комбинацию пропускает.

### A3 — один `SubjectAccess` квалифицирует автоматическую роль по двум разным моделям

**Severity: Major (семантика API/надёжность). Confidence: высокая для расхождения; намеренность refresh в helpers не подтверждена документацией.**

- Места: `packages/core/src/Concerns/SubjectAccess.php:172`, `:220`, `:228`, `:424` передают только `$this->ref`; `:367` сохраняет caller-held model для `decide/hasPermission`. `Authorization/Internal/Pipeline/Stages/PrepareStage.php:46` создаёт qualification request без `withSubjectModel()`.
- Триггер: automatic super-admin role читает `User::is_root`; один wrapper построен из сохранённой модели с актуальными для вызывающего кода атрибутами/relations. Значение в модели отличается от БД — например, middleware ограничил текущего пользователя, Auth держит snapshot либо роль использует already-loaded данные.
- Результат в обоих направлениях: held true / DB false → `decide=super_admin`, `isSuperAdmin=false`, `hasRole=false`, пустые roles/set. Held false / DB true → `decide=not_granted`, `isSuperAdmin=true`, `hasRole=true`, `roleNames=['root']`, две permissions. [Лог](../evidence/authorization/given-model.log).
- Влияние: меню, проверки helper-методами и policy-проверки на одном объекте расходятся; host-код, использующий `isSuperAdmin()` как shortcut, может отменить сознательное ограничение модели текущего пользователя. Helpers дополнительно перечитывают subject, теряя eagerly loaded inputs автоматической роли.
- Разграничение контракта: `permissionSet()` сознательно не применяет policies/restrictions — это корректное различие и не предмет находки. Здесь отличается вход **самой квалификации роли** без каких-либо policies/restrictions. `docs/advanced/performance.md:15` явно описывает held model для обычных проверок, bare-ref reread — для `SubjectRef`; helpers там отдельно не названы. `docs/concepts/roles.md:90` рекомендует automatic roles читать already loaded модель. Явной гарантии «helpers всегда authoritative fresh DB» не обнаружено.
- Исправление: передавать исходную validated model через общий qualification input, как `AccessRequest::withSubjectModel()`, во все role/set/superadmin пути. Если продукт действительно выбирает fresh DB для helpers, это должно быть отдельным явным режимом/именем с тестами и предупреждением о несовместимости с check path до freeze; безопаснее единая семантика wrapper.
- Acceptance: held true/stored false и обратный случай; eager-loaded relation; bare SubjectRef по-прежнему делает fresh read; одинаковый automatic role результат для `decide`, `hasRole`, `isSuperAdmin`, `roleNames`, `permissionSet` при отсутствии policies/restrictions. Отдельно проверить PanelAdmission, который также передаёт ref в квалификацию ролей.
- Связь с планом: `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.8` call sites qualification, `P3.3` read coordinator, `P4.2/P4.7` consumer semantics; это не требование вернуть старый API.

### A4 — коллизия typed decision fields переиспользует разрешение в batch

**Severity: Blocker. Confidence: высокая, воспроизведён fail-open.**

- Место: `packages/core/src/Authorization/Internal/BatchInputs.php:294`, `json_encode(..., JSON_THROW_ON_ERROR)` для eligibility key. `Kernel/Decision/Grant.php:125` допускает любой scalar/null/array, без UTF-8/finite ограничений; RoleContribution использует ту же валидацию.
- Триггер: volatile custom source возвращает grant для одного subject/permission/scope, беря field `score` из resource. Два запроса имеют соответственно `score=1` и `score=1.0`; scope evaluator разрешает только `is_int(grant.fields()['score'])`. Это чистая детерминированная проверка; `allowsMany()` применяет ту же функцию по каждому входу.
- Результат: `decide()` → `[granted, not_granted]`, `decideMany()` → `[granted, granted]`. `json_encode(['score'=>1]) === json_encode(['score'=>1.0])`, и batch кеширует первый разрешающий verdict под общим ключом. [Лог fail-open](../evidence/authorization/batch-numbers.log).
- Второе проявление той же проблемы: легальный custom-source grant с `fields: ['binary' => "\xB1"]`; поле ни одно правило не читает. Scalar и SQL разрешают все четыре ресурса, batch запрещает каждый с `context_filter_error`, потому что допустимая PHP string не является UTF-8 JSON. [Лог fail-closed](../evidence/authorization/batch-fields.log).
- Исправление: один канонический codec plain fields для evaluation keys, сохраняющий типы и бинарные строки. `JSON_PRESERVE_ZERO_FRACTION` исправляет продемонстрированную numeric collision, но отдельно необходимо согласовать остальной domain с Kernel; возможная альтернатива до freeze — явное ограничение допустимых fields в фабриках с одинаковым отказом всех путей. JSON replacement flags недопустимы: создадут новые коллизии.
- Acceptance: `1` и `1.0` в одном batch в обоих порядках и в nested fields; разные eligibility verdicts сохраняются. Binary string, Unicode, INF/NaN имеют одинаково определённый domain в factory/scalar/batch. Независимые contribution values не могут получать общий cache key, если evaluator способен их различить.
- Связь с планом: `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.8` scalar/batch equivalence, `P2.12` Kernel values.

### A5 — directory search предлагает scope, который `describe()` и final admission запрещают

**Severity: Minor. Confidence: высокая, воспроизведено; запись остаётся защищённой.**

- Место: `packages/core/modules/scopes/src/Internal/QueryScopeDirectory.php:111` проверяет только `tenantOf(record)` перед выдачей option в строке 118. Custom `definition->resolve(ref)` вызывается только в `describe()` (143), а `PanelDirectories` выполняет final admission для describe, не для каждого результата search.
- Триггер: тот же поддерживаемый custom structural resolver отказывает project:1, query его всё ещё перечисляет; lookup phase Assignment, target user выбран, роль отсутствует (direct grant).
- Результат: search → `project:1, project:2, project:3, project:4`; describe каждого найденного option → `null, project:2, project:3, project:4`. [Лог](../evidence/authorization/directory-resolver.log).
- Влияние: пользователь выбирает предложенный вариант и получает ошибку при сохранении; ошибка может вытеснить реально допустимый вариант под `limit`. Встроенный справочник не выполняет заявленную write-equal eligibility. Это не IDOR: final write validation остаётся на месте.
- Исправление: custom structural resolver должен участвовать в фильтрации candidate до расходования limit; применять общий admission/verification результат и сохранять chunk/scan budget. Оптимизированный query+tenantOf путь оставлять лишь при явном `resolvesThroughQuery=true`. Не вызывать полный повторяющийся lookup для каждого кандидата без переиспользования уже проверенных общих входов.
- Acceptance: для каждого предложенного option `describe(option.scope, тот же LookupContext)` ненулевой и write scope validation проходит; custom resolve null/foreign owner/wrong record; выбранный limit заполняется следующими допустимыми вариантами.
- Связь с планом: **`2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.9` прямо включает «no offered option is rejected by the write for a scope reason» и общую routine.** Это незакрытый acceptance case, а не новая directory feature.

## Что сохранить и что менять перед freeze

Контрольный сценарий [baseline.log](../evidence/authorization/baseline.log) даёт одинаковые scalar/batch/SQL `[101,102,103,104]`. Оба SQL дефекта точечные: scalar и batch в этих случаях уже согласованы. По коду сохранены полезные свойства: source envelope проверяется до квалификации, automatic role provenance берётся из фактического source object, ошибочный source не превращается в allow, bare ref lookup проверяет точную model identity, SQL собирается на clone до публикации, unsupported adapters отказывают явно, batch держит общий raw snapshot и не повторяет host policy из-за read retry. Наборы существующих тестов на эти свойства прочитаны; результат их общего выполнения даёт root-аудит.

Рекомендуемая последовательность рефакторинга:

1. Сначала зафиксировать A1–A5 воспроизводящими acceptance-тестами. Закрыть A1/A2/A4 до публикации кандидата.
2. Выделить **общий внутренний результат подготовки/квалификации**: validated subject model/ref, verified structural scope, qualified contribution + его ограничения. Scalar/batch/query должны использовать одну семантику envelope и structural verification. SQL-проекция должна честно отмечать значения, которые вычисляются только на строке; пустой PHP array не заменяет неизвестный набор grants.
3. Протянуть один prepared subject input через wrappers/admission; согласовать plain-fields codec. Эти изменения в основном внутренние, не требуют общей перестройки namespaces или нового plugin framework.
4. Проверить паритет по пересечениям SPI, а не только по одному адаптеру: flags description × custom resolve; grant fields × restriction/policy; held model × automatic role × helper; queryable definition × directory × final write. Это компактная матрица, непосредственно закрывающая найденные разрывы.

Отдельное предложение API, **не дефект текущего принятого контракта**: разрешить явный стабильный id у `RelationSource`. Сейчас id всегда `relation:<scope-type>` (`Sources/Relation/Internal/RelationBinding.php:86`), поэтому один проект нельзя одновременно авторизовать по `members` и по `lead`. Этот запрет сознательно закреплён `tests/Feature/Sources/Relation/RelationBuildTest.php:70`. Реальный consumer scenario уже находится прямо в тесте: `members → pivot.role` + `lead → owner`. Опциональный named id с прежним default позволит композицию без написания собственного Source; acceptance — два relation source одного type дают независимые вклад/trace и не конфликтуют. Решение стоит принять до freeze, сохранив backward-compatible default и source provenance.

Не предлагаю переписывать весь authorization engine: подтверждённые разрывы сосредоточены в расхождении контекстов и повторной реализации structural/qualification правил. Общий внутренний artifact и честная SQL capability boundary адресуют именно измеренную проблему.
