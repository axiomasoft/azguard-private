# Аудит решений по находкам P4: A04 и A05

Роль: независимый read-only аудитор. Дата: 2026-10-07. Код не менялся. Единственный созданный файл репозитория — этот отчёт.
Условные обозначения: **[код]** — прочитано в коде на HEAD `34fd55c`; **[запуск]** — получено запуском; **[источник]** — по внешнему первоисточнику; **[предположение]** — моё допущение.

## 1. Резюме

| Находка | Рекомендация | Уверенность |
|---|---|---|
| **A04** (политика вызывается до `NotGranted`) | **Вариант 1**: при Grants-режиме без квалифицированного вклада возвращать `NotGranted` до вызова политики. Обновить 3 теста (4 кейса), строку 09 §5 и R22. Если владелец не готов менять R22 — запасной **вариант 3b** (политика вызывается, но её исключение не маскирует `NotGranted`): он меняет 0 существующих тестов, но оставляет вызов чужого кода и «oracle» причины. | **Средняя.** Безопасность и устойчивость за вариант 1; но 09 §5 буквально («Grants / любое / false → Deny(Policy)») и 4 осознанных теста подтверждают текущий порядок, то есть это неоднозначность спецификации, а не ошибка реализации. |
| **A05** (`appliesTo`, зависящий от ресурса, в списках) | **Вариант 1 в сужённой форме («1b»)**: для ограничений, реализующих `FiltersAccessQueries`, предикат компилируется всегда, применимость кодируется внутри `predicate()` (`P::pass()` для неприменимых); `appliesTo` пропускает предикат только у ограничений без адаптера, и для них в SPI явно записано «не зависит от ресурса». Ломает 0 существующих тестов, закрывает воспроизведение, публичный API не меняется (`composer api:manifest` не нужен). | **Средняя-высокая** для адаптерных ограничений. Остаточный риск у ограничений без адаптера описан в §3.5. |

Главное по результатам запусков (на копиях в scratchpad, не в репозитории, см. §6): A04/вариант 1 → ровно 4 новых падения в 3 файлах; A04/вариант 3b → 0; A05/вариант 1 «чистый» → 1 падение (`UnsupportedTest`, P4.12); A05/вариант 1b → 0; A05/вариант 3 → то же 1 падение плюс сам репро (так и задумано).

## 2. A04 — политика вызывается раньше `NotGranted`

### 2.1 Факты из кода

**а) Точный порядок сейчас [код].** Один код для всех путей: `AuthorityStage::decide` (`packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php:47-104`), вызывается из `AccessPipeline::evaluate:35`; скаляр (`Authorizer.php:190`), `decideMany` (`BatchEvaluation.php:222`) и explain идут через тот же `evaluate`.

1. `:51-56` Grants: `qualify()`; ошибка источника/условия/фильтра → сразу Deny (`SourceError`, `ConditionError`, …).
2. `:62-77` Membership проверяется **только** при `$qualified || authority === Policy` (`:65`).
3. `:79-96` `PolicyDecider::decide(..., qualified: $qualified)` вызывается **всегда**, в том числе при `qualified=false`. Результат `Deny(Policy)` (`PolicyDecider.php:80-82`) или исключение → `Deny(PolicyError)` (`AuthorityStage.php:90-95`).
4. `:98-100` Только после этого `Grants && !qualified` → `Deny(NotGranted)`.

Неоднозначность текста: 09 §2 шаг 3b — «Если authority отсутствует, Deny(NotGranted). Attached policy true/null → pass, false → veto» читается последовательно (сначала NotGranted). Но 09 §5 (таблица, `09-authorization-semantics.md:183`) содержит строку **«Grants | любое | false / deny Response | Deny(Policy)»**, где «любое» — любое состояние назначения, то есть явно допускает `Deny(Policy)` без назначения. P4.1 (Scope) перечисляет «Нет квалифицированного вклада → `Deny(NotGranted)`» раньше строки про veto; P4.3 (Code Guidance V80) требует проверять «таблицу 09 §5 построчно», а из проверок «без выдачи» называет только `true`/before → `NotGranted`, про `false` без выдачи не говорит ничего. Итого: **текст допускает оба прочтения**, текущая реализация следует §5 буквально и это закреплено тестами (ниже). Строка R22 в досье (`17-crm-acceptance-tests.md:80`) говорит «RequiresGrant без assignment deny» без причины.

**б) Причины/исключения [код + запуск].** Зонд (временный тест, удалён), пользователь без назначений, права Grants:

| Результат политики | Сейчас: `reason`, `component` | Вызов чужого кода |
|---|---|---|
| `false` | `policy`, `ClientPolicy` | да |
| `null` | `not_granted`, null | да (вызван, результат неважен) |
| `true` | `not_granted`, null | да |
| `Response::deny(...)` | `policy` (+message/status/code) | да (1 вызов) |
| исключение (`Response::message()` бросает) | `policy_error`, `ClientPolicy` | да (1 вызов) |
| `decideMany`, `false`, без назначения | `policy`, `ClientPolicy` | — (то же, что скаляр) |

То есть: (1) host-код политики исполняется на каждой проверке субъекта без права; (2) причина зависит от атрибутов ресурса даже для субъекта без права (`do_not_call` в R21 виден как `Policy` против `NotGranted`) — это канал разглашения состояния ресурса через `Decision->reason`; (3) исключение/TypeError политики превращает отказ в `PolicyError`, маскируя `NotGranted`; (4) ни в одном из этих случаев Allow невозможен (`AuthorityStage.php:98-100` всё равно закрывает).

Контекст: host-код без права исполняется и в других местах конвейера — `before` хуки идут раньше authority (`AccessPipeline::start`), `GrantCondition` и `ScopeEligibility` исполняются для каждого вклада до проверки `covers` (`AuthorityStage.php:203-229` против `:243-249`). Принцип «чужой код не вызывается без права» в движке уже не выдержан; вариант 1 затронул бы только политику.

**в) Тесты и реестр [код + запуск].** Порядок закреплён **четырьмя кейсами в трёх файлах**, а не одним:

- `tests/Acceptance/Crm/PoliciesTest.php:34` — R22, наборы `'false'` и `'deny'` без назначения ожидают `Policy`.
- `tests/Feature/Policies/PolicyModesTest.php:29` — набор `'veto without grant' => [false, false, DecisionReason::Policy]`.
- `tests/Feature/Authorization/AuthorityDispatchTest.php:34` — ожидает `RuntimePolicy::$calls === 1` при `NotGranted` (сам факт вызова — предмет assert).

Строки реестра: R22 в `views/knowledge/brief-P4-acceptance-matrix.md:137` («P4.15 — Authorizer literal controls, source spy…») и `17-crm-acceptance-tests.md:80`; контракт P4.3 Code Guidance V80; 09 §5 строка 183.

**г) Паритет путей [код + запуск].** Скаляр, `decideMany` и explain используют один `AuthorityStage::decide`, расхождения порядка нет. `Visibility` строит `(branches) AND NOT policy_deny` (`Visibility.php:203`): для списка причина не существует, а строки без назначения исключены ветками, поэтому порядок скаляра на список (P14) не влияет ни при одном из вариантов. Режим Policy не затронут. `exact`-ветки — одна и та же логика Grants.

### 2.2 Варианты

Эксперимент: копия репозитория в scratchpad, правка `AuthorityStage.php`, полный `pest --exclude-group=engines`; сравнение множества упавших тестов с базовой копией (в копии без `plans/` и `audits/` 40 базовых падений — `RegressionSpecsTest` и Redis, не связаны).

| Критерий | 1. `NotGranted` до политики | 2. Оставить, зафиксировать отклонение | 3b. Политика всегда, исключение не маскирует `NotGranted` |
|---|---|---|---|
| Безопасность | Wrong Allow невозможен; убирает канал `Policy`/`NotGranted` для субъекта без права, побочные эффекты и NPE host-кода на неавторизованных запросах | Wrong Allow невозможен; канал, побочные эффекты и маскировка остаются | Wrong Allow невозможен; убирает только маскировку исключением; канал `Policy` и вызов host-кода остаются |
| Соответствие 09/плану | 09 §2 шаг 3b и порядок пунктов P4.1; противоречит буквальному §5 «любое» → нужна правка строки таблицы | 09 §5 буквально; нужна запись отклонения от §2 | Не соответствует ни одной из формулировок целиком; нужна новая запись |
| Паритет скаляр/`decideMany`/list | Сохранён (один код); explain: шаг `policy` = `skipped` | Сохранён | Сохранён |
| Публичный API, SPI | Не меняется; `PolicyDecider::decide` теряет смысл параметра `qualified` для false-ветки (внутренний) | Не меняется; SPI-документация должна требовать «политика безопасна для субъекта без права» | Не меняется |
| Тесты [запуск] | **4 кейса в 3 файлах** падают: ожидания `Policy`→`NotGranted` ×3, `calls 1`→`0` ×1 | 0 | **0** падений |
| Обратная совместимость | Пользователи, читающие `reason` без права, увидят `NotGranted` вместо `Policy`; пакет не выпущен (в `v0.3.0` ни `Restriction`, ни `PolicyDecider` нет) | Нет | Нет |
| Ошибка политики без права | `NotGranted`, политика не вызвана | `PolicyError` | `NotGranted` (но политика вызвана) |

### 2.3 Внешние ориентиры [источник]

Цитаты получены через `WebFetch` (страница обрабатывается небольшой моделью-пересказчиком), поэтому дословность не гарантирована. Perplexity (`perplexity-web-mcp`, версия сервера 1.4.0) в сессии не подключён как MCP-инструмент; по просьбе владельца я запускал его вручную по stdio (`start.sh --pool=default`, `tools/call search`) и использовал как второй источник: AWS-формулировка подтверждена (в т.ч. «A request results in an explicit deny if an applicable policy includes a Deny statement», страница `…_AccessPolicyLanguage_Interplay.html`). context7 недоступен.

- **AWS IAM**, https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_evaluation-logic_policy-eval-denyallow.html — «By default, all requests are implicitly denied», «An explicit deny overrides an explicit allow», «In all those policies, the enforcement code looks for a `Deny` statement that applies to the request … If the enforcement code finds even one explicit deny that applies, the enforcement code returns a final decision of **Deny**». Вывод: явный запрет вычисляется независимо от разрешений, различие explicit/implicit deny в первоисточнике есть.
- **Google Cloud IAM deny**, https://docs.cloud.google.com/iam/docs/deny-overview — «IAM always checks relevant deny policies before checking relevant allow policies.» и «If the condition evaluates to `true` or cannot be evaluated, the deny rule applies». Вывод: deny-first; невычислимое условие запрета — закрыто.
- **Laravel 12 Authorization**, https://laravel.com/docs/12.x/authorization — «If the `before` closure returns a non-null result that result will be considered the result of the authorization check»; «The `before` method of a policy class will not be called if the class doesn't contain a method with a name matching the name of the ability being checked». В Laravel нет отдельного «права»: сама политика и есть authority и вызывается для любого пользователя, поэтому вопрос порядка «authority → политика» там не возникает.

**Предварительная гипотеза: подтверждена с поправкой.** AWS/GCP действительно вычисляют запрет независимо от разрешения и различают explicit/implicit deny. Но их «политики» — декларативные данные, исполняемые движком вендора: нет пользовательского кода, побочных эффектов и исключений (для GCP невычислимое условие запрета даже трактуется как срабатывание запрета — детерминированно). В AzGuard политика — PHP-код хоста (может бросать, ходить в БД, иметь побочные эффекты). Что важнее для пакета: (а) причина «явный запрет» против «нет права» — полезна, когда запрет вычислен чистым кодом; (б) для кода хоста это не окупает вызов без права, при этом ошибка чужого кода маскирует осмысленную причину. Мой вывод: независимость запрета от разрешения у внешних систем **не** переносится на host-код без оговорок, поэтому пример AWS/GCP не оправдывает вариант 2, но и не опровергает его (причина «Policy» для субъекта без права — их же поведение).

### 2.4 Рекомендация

**Вариант 1.** Обоснование: (1) совпадает с последовательным чтением 09 §2 3b и порядком P4.1; (2) убирает исполнение host-кода и разглашение состояния ресурса через причину для субъектов без права; (3) пакет не выпущен, потребителей причины нет; (4) стоимость измерена: 4 кейса. Если владелец хочет сохранить «Policy» для явного veto — вариант 3b закрывает только маскировку исключением.

### 2.5 Точный список изменений при варианте 1

Новые тесты (предлагаю в `tests/Feature/Policies/PolicyModesTest.php` или новом файле того же каталога):
- spy-политика без назначения для `true`/`false`/`null`/`Response::deny`/исключение → `NotGranted`, число вызовов 0 (Grants);
- то же через `decideMany` и `explain` (шаг `policy` = `skipped`);
- положительный контроль: с назначением и `false` → `Policy`, исключение → `PolicyError`, счётчик 1;
- Policy-режим не затронут (политика вызывается всегда).

Изменяемые существующие (только решением владельца, ожидания меняются, а не ослабляются):
- `tests/Acceptance/Crm/PoliciesTest.php:34`: ожидание без назначения → `NotGranted` для всех пяти наборов.
- `tests/Feature/Policies/PolicyModesTest.php:29`: `'veto without grant' => [false, false, DecisionReason::NotGranted]`.
- `tests/Feature/Authorization/AuthorityDispatchTest.php:34`: `RuntimePolicy::$calls` 1 → 0.

Правки реестра/решений: 09 §5 — строку «Grants | любое | false → Deny(Policy)» разделить: «Grants | false | любое → Deny(NotGranted), политика не вызывается» и «Grants | true | false/deny → Deny(Policy)»; 09 §2 шаг 3b — уточнить, что политика вызывается только после квалификации; R22 (`17-crm-acceptance-tests.md:80` и строка матрицы) — дописать «без назначения политика не вызывается»; P4.3 Code Guidance V80 — добавить строку; новая запись решения плана; `CHANGELOG.md` `[Unreleased]`. Правка кода: один ранний возврат в `AuthorityStage::decide`, трасса `policy` = `skipped`, затем упрощение параметра `qualified` в `PolicyDecider` (необязательно).

### 2.6 Вопрос владельцу (одно предложение)

Для права в режиме Grants без квалифицированного назначения возвращать `NotGranted` без вызова политики (4 существующих ожидания меняются), или оставить вызов политики и причину `Policy`/`PolicyError` как в 09 §5?

## 3. A05 — `appliesTo`, зависящий от ресурса

### 3.1 Воспроизведение [запуск]

`VisibilityAppliesToTest` (копия во временном `tests/Feature/ZzAuditTmp/`, удалена; `uses(TestCase::class)` убран, так как `tests/Pest.php` уже назначает класс; добавлен `require_once` фикстуры):

```
php -d memory_limit=1G vendor/bin/pest tests/Feature/ZzAuditTmp/VisibilityAppliesToTest.php
"scalar" => [101]     "list" => [101, 102]
Failed asserting that two arrays are identical. ... +    1 => 102
```

Список шире скаляра: строка 102 показана, хотя обязательное ограничение «только Paris» её запрещает.

### 3.2 Где вычисляется и что получает скаляр [код]

- Список: `Visibility::inputs` (`Visibility.php:235-259`) строит `AccessRequest::for(...)->inScope($scope)` и `EvaluationFrame` **без ресурса** (`:256-257`: `subject`/`actorSubject` = модель субъекта, `selectedResource` по умолчанию null). `restrictions()` (`:313-333`) вызывает `appliesTo($request, $frame)` (`:325`) и при `false` пропускает `predicate()`; вызывается из `build` на ветке Policy (`:143`) и **на каждую ветку вклада** (`:199`).
- Скаляр: `PrepareStage.php:99` кладёт `selectedResource: $request->resource()` в frame; `RestrictionStage::decide:72` вызывает `appliesTo($request, $frame)` с конкретным ресурсом.
- Итог: список и скаляр передают в `appliesTo` разные входы; пропуск предиката в списке по ответу на «ресурса нет» эквивалентен «не применимо ни к одной строке».

### 3.3 Ограничения и фикстуры [код]

Все реализации `Restriction` в репозитории:

| Класс | `appliesTo` | Зависит от ресурса | Адаптер | Реакция на варианты |
|---|---|---|---|---|
| `AccountLockedRestriction` (CRM) `tests/Fixtures/Crm/.../AccountLockedRestriction.php:23` | `true` | нет | да | не затронут ни одним вариантом |
| `VisibilityRestriction` `tests/Fixtures/Visibility/VisibilityWorld.php:236` | `self::$applies` (статика, нигде не выставляется в `false`) | нет | да | не затронут; предикат не учитывает `$applies` — при варианте 1 нужно будет выровнять фикстуру |
| `VisibilityInapplicableRestriction` `UnsupportedTest.php:50` | `false` | нет | **нет** | **ломается** при чистом варианте 1 и при варианте 3 (`UnsupportedTest.php:116`) |
| `MembershipRestriction` `Scopes/MembershipRestriction.php:26` | зависит от панели | нет | идёт отдельным путём `Visibility::membership` | не затронут |
| `PassRestriction`, `TokenAbilitiesRestriction`, `RecordingRestriction`, анонимный `ExplainTest.php:139` | `true`/`$applicable` | нет | нет | Visibility-тестов с ними нет |
| репро A05 (анонимный класс) | `$request->resource() !== null` | **да** | да | вариант 1/1b закрывают; вариант 3 падает (ожидаемо) |

Ни одна существующая фикстура, включая CRM, не имеет зависящего от ресурса `appliesTo`; кроме репро, зависимость от ресурса встречается только там.

### 3.4 D15 §6 и P14 [код]

D15 §6: «Predicate — host WHERE AND owner/tenant AND common eligibility AND before-pass AND **restrictions** AND authority»; «Компонент без такого exact adapter даёт `VisibilityNotSupportedException` **до** count/order/limit/pagination». P4.12 (Scope): «компонент, детерминированно не применимый к запросу (`appliesTo` false), — `pass`». 09 §2 P14: «`visibleTo` в exact режиме возвращает ровно записи, разрешённые decide». Противоречие внутри плана: «детерминированно не применимый к запросу» корректно только для `appliesTo`, не зависящего от ресурса; для зависящего — нарушение P14 и 09 §10 («Неизвестный volatile ответ не заменяется false или true ради удобного SQL»).

### 3.5 Варианты

Эксперимент как в §2: правка `Visibility.php:325` в копиях + полный прогон; репро запускалось на каждой копии.

| Критерий | 1. Всегда компилировать | 1b. Всегда компилировать для адаптерных; без адаптера — как сейчас | 2. Требовать независимости + проверка | 3. Fail-closed | 4. Трёхзначная применимость |
|---|---|---|---|---|---|
| Лишние строки/wrong Allow | нет для любых ограничений с адаптером | нет для адаптерных; **остаётся** для безадаптерных (`appliesTo` false → пропуск) | зависит от полноты проверки | нет | нет |
| Соответствие D15 §6/P14 | да | да для адаптерных | формально да | да, но не даёт результата | да |
| Соответствие P4.12 «`appliesTo` false → pass» | отменяет для безадаптерных | сохраняет для безадаптерных | сохраняет | отменяет | сохраняет для «нет» |
| Паритет скаляра/`decideMany` | да, при кодировании применимости в предикате | да | как есть | да (ошибка) | да |
| Публичный API | нет (docblock) | нет (docblock) | нет или новое исключение | нет | **да**: новый метод/интерфейс SPI → `api-manifest.json` и `composer api:manifest` |
| Репро [запуск] | `scalar [101] / list [101]` | то же | не проверялось | `VisibilityNotSupportedException restriction_applies_to` | не прототипировалось |
| Существующие тесты [запуск] | 1 падение: `UnsupportedTest.php:116` (`missing_exact_adapter`) | **0 падений** | — | 1 падение: тот же + репро | — |
| Побочные эффекты | авторы с `appliesTo`, ограничивающим по **праву/запросу** (не по ресурсу), получат пустой/узкий список, если `predicate()` не закодирует это же | то же для адаптерных | ложные срабатывания проб | любой `appliesTo=false` даёт ошибку на списке, в т.ч. легитимный | сложнее всех |
| Обратная совместимость | `Restriction`, `FiltersAccessQueries`, `Visibility` отсутствуют в `v0.3.0` — потребителей нет | то же | то же | то же | то же |

**Вариант 2** (проверка независимости): статически проверить нельзя, динамически — вызвать `appliesTo` с фиктивным ресурсом (пустая модель/`stdClass`) и сравнить с ответом без ресурса. Ложные отрицания: условие вида `$context->resource()?->city === 'Paris'` на фиктивном ресурсе даёт то же, что без ресурса, расхождения нет, а зависимость есть. Ложные срабатывания: допустимое различие между `null` и ненулевым ресурсом, не влияющее на строки. Не прототипировал (требует конструирования `EvaluationFrame` с ресурсом); оценка — [предположение]. Эвристика не доказывает независимость, поэтому как единственный механизм не годится.

**Вариант 4** (три значения): без нового SPI реализуется как вариант 1 — `predicate($request, $resourceType, $context)` и так вызывается с запросом без ресурса и может вернуть условие по колонкам (аналог Cerbos «conditional»). Добавлять отдельный трёхзначный `appliesTo` имеет смысл только с новым методом SPI и изменением манифеста; выигрыш против варианта 1 — нет дублирования условия в `appliesTo` и `predicate()`; цена — публичный API до релиза. Не рекомендую сейчас.

**Остаточный риск варианта 1b.** Безадаптерное ограничение с зависящим от ресурса `appliesTo`, вернувшим `false` на запросе без ресурса, по-прежнему молча пропускается (список шире скаляра). Закрыть полностью можно только вариантом 3 для этого подслучая (переписать `UnsupportedTest.php:116`, то есть решение владельца) либо документировать контракт «`appliesTo` ограничений без адаптера не зависит от ресурса».

### 3.6 Внешние ориентиры [источник]

- **Cerbos PlanResources**, https://docs.cerbos.dev/cerbos/latest/api/ — «KIND_ALWAYS_ALLOWED: The principal is unconditionally allowed to perform the action», «KIND_ALWAYS_DENIED: …not permitted…», «KIND_CONDITIONAL: …allowed … if the condition is satisfied»; «If an effective policy rule condition(s) requires a resource attribute not present in this object, then the response will contain the condition(s) abstract syntax tree.» Условие на неизвестный атрибут ресурса сохраняется в плане, а не отбрасывается.
- **OPA Compile API**, https://www.openpolicyagent.org/docs/rest-api — «The terms to treat as unknown during partial evaluation (default: `["input"]`)», «The query is partially evaluated and remaining conditions are returned.» Остаток условий возвращается вызывающему. Перевод остатков в SQL **подтверждён первоисточником** (повторная проверка `WebFetch` по той же странице): «For example, this post on the OPA blog shows how SQL can be generated based on Compile API output.» и «The same request can generate filters that are representable in many different ways, such as raw SQL `WHERE` clauses or Universal Conditions AST (UCAST).»; Perplexity дополнительно привёл туториал OPA («Only expressions involving the unknown input.employees survived as residual conditions, which OPA then translated into SQL» — только со слов Perplexity, не перепроверено). Блог OPA (`https://openpolicyagent.org/blog/partial-evaluation-162750eaf422`): «With partial evaluation, callers specify that certain inputs or pieces of data are unknown.» «The result of partial evaluation is a new policy…».
- **Oso list filtering**, https://www.osohq.com/docs/develop/enforce/list-filtering.md — «List filtering retrieves only the resources a user can access in a single operation, instead of checking each resource individually.»; `list_local` генерирует SQL `WHERE`. Через Perplexity: в документации Oso есть определения «Authorize: Check if an actor is allowed to perform an action on a specific resource» и «List Resources: Get all resource IDs that an actor can perform an action on», а также «Both use the same Polar policies and facts» (относится к centralized/local, а не к list/authorize). Прямого утверждения «совпадает с authorize» нет — эквивалентность остаётся интерпретацией; там же оговорка, что list() требует ограниченного домена переменных и может не работать там, где authorize() работает. Цитаты Oso не перепроверены на самой странице.
- **PostgreSQL RLS**, https://www.postgresql.org/docs/current/ddl-rowsecurity.html — «combined using either OR (for permissive policies…) or using AND (for restrictive policies)»; «This expression will be evaluated for each row prior to any conditions or functions coming from the user's query»; «If no policy exists for the table, a default-deny policy is used». Условие, зависящее от строки, — часть фильтра, а не пропуск.

**Гипотеза «зависящее от ресурса условие не пропускается, а входит в фильтр»: подтверждена** тремя системами (Cerbos, OPA, PostgreSQL RLS); для Oso — только косвенно (определения API, без утверждения об эквивалентности). Для нашего API это отражается без публичной смены сигнатур: `predicate()` уже получает запрос без ресурса и возвращает булево дерево по колонкам; достаточно сделать этот метод единственным источником применимости в списках (вариант 1). `packages/core/api-manifest.json` содержит только сигнатуры (проверено по записи `Restriction`, строки 1859-1925), правка docblock его не меняет, `composer api:manifest` не нужен.

### 3.7 Рекомендация

**Вариант 1b.** Правило для SPI: «в списках `appliesTo` ограничения с `FiltersAccessQueries` не вызывается; применимость кодируется в `predicate()` (`P::pass()` для неприменимых); у ограничений без адаптера `appliesTo` должен зависеть только от запроса/субъекта/панели, не от ресурса». Остаточный риск безадаптерных вынести отдельной строкой владельцу.

### 3.8 Точный список изменений при варианте 1b

Код (для информации, не выполнялось): `Visibility.php:325` — условие `appliesTo` применять только если ограничение не реализует `FiltersAccessQueries`; docblock `Restriction::appliesTo` и `FiltersAccessQueries` (в `packages/core/src/Contracts/Authorization/`) дополнить правилом выше.

Новые тесты: перенести репро в `tests/Feature/Visibility/` (регрессия `scalar === list`); кейс в `ParityPropertyTest` («restriction» с `appliesTo`, зависящим от ресурса, и предикатом, кодирующим применимость, на семенах); кейс «`appliesTo=false` + предикат `P::pass()` для неприменимого → список равен скаляру»; кейс адаптерного ограничения с `appliesTo` по праву (`orders.export` vs `orders.view`), показывающий, что применимость по праву должна кодироваться в `predicate()`.

Изменяемые существующие: **ноль** при 1b. `VisibilityWorld.php:251` (`VisibilityRestriction::predicate`) имеет смысл выровнять с `$applies` (возвращать `P::pass()` при `!$applies`) — это правка фикстуры, не ожидания. При «чистом» варианте 1 дополнительно ломается `tests/Feature/Visibility/UnsupportedTest.php:116`.

Правки реестра: P4.12 Scope (строка «`appliesTo` false → pass») уточнить «для ограничений без адаптера, если `appliesTo` не зависит от ресурса»; D15 §6 — добавить фразу о том, что для адаптерных ограничений применимость кодируется в предикате; запись решения; `CHANGELOG.md` `[Unreleased]`.

### 3.9 Вопрос владельцу (одно предложение)

Закрыть A05 так, чтобы для ограничений с `FiltersAccessQueries` применимость в списках кодировалась только в `predicate()` (а `appliesTo` без адаптера считается независимым от ресурса), или дополнительно запретить пропуск по `appliesTo=false` у ограничений без адаптера с ошибкой `VisibilityNotSupportedException` (потребует переписать `UnsupportedTest.php:116`)?

## 4. Что не удалось проверить и почему

- Полный прогон набора в самом репозитории не запускался (по условию — только временные копии тестов). Прогоны выполнены на копиях в scratchpad **без** `plans/` и `audits/`, поэтому 40 базовых падений (`RegressionSpecsTest` читает `plans/` и досье; `RedisStoreTest` нужен Redis) — артефакт копии; сравнение шло по множеству имён упавших тестов к базовой копии. Утверждение отчёта исправлений о 3023 passed в Docker не перепроверялось.
- Вариант 2 для A05 (динамическая проба независимости) и вариант 4 (трёхзначный SPI) не прототипировались; оценки — рассуждения (отмечены [предположение]).
- context7 в сессии недоступен; Perplexity подключён не как MCP-инструмент сессии, а запущен вручную по stdio и использован как вторичный источник. Внешние страницы читались через `WebFetch` (пересказ малой модели), цитаты не гарантируют дословности; OPA «остатки → SQL» перепроверено на странице, цитаты Oso и туториал OPA — только со слов Perplexity. Утверждение Oso о консистентности list и authorize в первоисточнике прямого текста не имеет. AWS-страница не использует слова «независимо от разрешения», это моё чтение шага «Deny evaluation».
- `ReplicaLagTest`, Redis, Infection, coverage-gate, engines (PostgreSQL/MySQL/MariaDB) не запускались; на выводы A04/A05 не влияют.
- Идентификатор D15 в досье (`02-decisions.md:401`) и D15 плана (`decisions/D15.json`) — разные пространства; D15 §6 здесь означает решение плана.

## 5. Пересмотр severity

- **A04: minor → оставить `minor` (дизайн/неоднозначность спецификации, не дефект кода).** Wrong Allow невозможен; влияние — причина отказа, побочные эффекты host-кода без права, канал разглашения через `reason`. Реализация следует 09 §5 буквально и закреплена тремя файлами тестов, значит находка — нерешённая неоднозначность, а не нарушение.
- **A05: minor → предлагаю `medium`.** Лишние строки в списке при нарушении обязательного ограничения (нарушение P2/P14), то есть утечка данных, а не лишняя причина. Смягчающее: условие — `appliesTo`, зависящий от ресурса (в репозитории такого ограничения нет), пакет не выпущен, wrong Allow для скалярного пути отсутствует.

## 6. Проверки, которые я реально запускал

| # | Проверка | Результат |
|---|---|---|
| 1 | Репро A05 (`VisibilityAppliesToTest`, временная копия в `tests/Feature/ZzAuditTmp/`) | падает: `scalar [101]`, `list [101, 102]` |
| 2 | Зонд A04 (временный тест в `tests/Acceptance/Crm/ZzAuditTmp/`, spy `Response`-класс) | таблица §2.1б; 8 сценариев, 1 passed (информационный) |
| 3 | `pest tests/Acceptance/Crm/PoliciesTest.php` в копии | 6 passed (до правок) |
| 4 | Полный `pest --exclude-group=engines` в копии без правок (baseline) | 40 базовых падений в копии (артефакты копии) |
| 5 | A04 вариант 1 (раннее `NotGranted`) | +4 новых падения: `PoliciesTest` R22 `'false'`, `'deny'`; `AuthorityDispatchTest.php:34`; `PolicyModesTest` `'veto without grant'` |
| 6 | A04 вариант 3b | +0 новых падений |
| 7 | A05 вариант 1 (всегда компилировать) | репро проходит (`list [101]`); +1 падение: `UnsupportedTest` `passes a deterministically irrelevant restriction without an adapter` |
| 8 | A05 вариант 1b (сужённый) | репро проходит; +0 падений |
| 9 | A05 вариант 3 (fail-closed) | репро: `VisibilityNotSupportedException restriction_applies_to`; +1 падение (то же `UnsupportedTest`) |
| 10 | Внешние страницы: AWS, GCP, Laravel, Cerbos, OPA (REST API, блог), Oso, PostgreSQL через `WebFetch` | прочитаны, цитаты в §2.3 и §3.6 |
| 10а | `perplexity-web-mcp` вручную по stdio: `initialize`, `tools/list` (`search`, `search_advanced`, `search_deep`, `login`), 3 вызова `search` (AWS, OPA, Oso) | работает; ответы с цитатами использованы как вторичные |
| 11 | Очистка | временные тесты удалены; `git status` не содержит следов, кроме pre-existing 7 изменённых файлов `plans/…/views/` (были до моей работы) и этого отчёта |

Все эксперименты с правками кода выполнялись только в копиях под scratchpad; файлы `packages/**`, `tests/**`, `docs/**`, `CHANGELOG.md` в репозитории не менялись.
