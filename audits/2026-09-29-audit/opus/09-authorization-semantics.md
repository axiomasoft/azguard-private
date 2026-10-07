# 09 — Смысл проверки прав (нормативно)

Целевая 1.0. Основа — D05, D15–D20, D24–D27, D31, D48, D52–D55; уточнения D59–D83
из [02](02-decisions.md). Сценарии — [CRM и цепочки](16-crm-and-workflows.md), проверки — [14](14-verification.md).

## 1. Как выбирается панель

Один `PanelResolver` для трейта, фасада, middleware, Gate, Blade, UI, CLI и `decideMany`.

| Шаг | Сигнал | Результат |
|---|---|---|
| 1 | `guard`, `panel:`, полное имя `x:orders.view`, зарегистрированный префикс, enum с единственной панелью | Собрать **все** явные сигналы; они должны согласоваться |
| 2 | Нет явных сигналов: панель маршрута/Filament, принимающая модель субъекта | Текущая панель запроса |
| 3 | Нет текущей: `azguardDefaultPanel`, default панели, единственная панель модели | Панель модели |
| 4 | Не удалось выбрать | PanelNotResolvedException |

`guard('a')->hasPermission('b:orders.view')` -> `ConflictingPanelException`, без смены панели.
Enum нескольких панелей требует явной панели; enum одной панели, вызванный через другую, конфликтует.
Сначала проверяется принимаемый субъект; принадлежность **модели** панели не означает членства человека в tenant.

Префикс уникален; его словарь ищет первый сегмент O(1). Конфликт с локальным namespace запрещён
для статичного каталога при сборке и для dynamic name внутри mutation во всех тенантах.
Полные имена с `:` всегда однозначны. Изолированные слова Gate остаются Laravel, кроме явного model binding.
Модель одного домена в двух панелях требует явной/текущей панели; модель двух ресурсов **одной панели** требует
явного имени права. Class argument (`Order::class` для `viewAny/create`) — binding модели без экземпляра.

Явно квалифицированное имя зарегистрированной панели всегда принадлежит AzGuard, даже если action неизвестен:
Gate возвращает отказ, direct API — UnknownPermissionException. Только действительно чужая ability -> null.
Middleware восстанавливает прежние panel/tenant/context/actor в finally, включая sync jobs и вложенный вызов.
Laravel Context переносит в queue лишь panel hint; tenant/context job передаёт явно и перепроверяет при исполнении.

## 2. Пайплайн проверки: алгоритм

```
вход: subject S, permission K, tenant?, context?, resource R?, trace
0. Resolve panel/subject и static permission metadata без resolving assignment services.
   Все explicit hints согласуются. Static definition содержит ровно один authority mode.
   Dynamic lookup при отсутствии static key допустим только opt-in; dynamic authority = Grants.
1. Resolve resource scope, owner/member/common eligibility; captured now/build id.
   TenantRequired / TenantMismatch / AssignmentScopeMismatch / ResourceScopeMissing -> Deny.
2. Все preliminary before checks: Deny/error -> отказ, Continue -> следующий шаг.
   Before не может дать authority. Ни Allow shortcut, ни порядок sources не скрывают ошибки.
3a. Policy mode: не resolve/read grants/roles/writer/panel_state.
    Вызвать единственную policy binding; true/Response::allow -> candidate;
    false/null/Response::deny -> Deny(Policy); error -> deny.
    Evidence CodeStateToken версии code catalogue, не фиктивный DB token.
3b. Grants mode: собрать required assignment sources с их state/dependency fence.
    Source error -> Deny(SourceError). Scope/expiry/conditions/context filters внутри одной contribution AND.
    Actual BaseRole/user/grant относятся к этой ветке. Role contributions expand только Grants actions.
    Qualified direct/fixed/relation/role grants OR scoped superadmin -> authority candidate.
    Если authority отсутствует, Deny(NotGranted) и attached policy не вызывается. При квалифицированной authority
    attached policy true/null -> pass, false -> veto.
    Policy не заменяет grant и не обходится hook/superadmin. Relevant source failure не прячется allow.
4. Candidate проходит owner/common/context/token boundaries и mandatory Restrictions.
   Scope membership exemptions только explicit; immutable owner boundary не освобождает никого.
5. Confirm code build / consumed source states по declared freshness protocol, вернуть Decision.
   after/tracing только наблюдает; exception логируется, ответ не меняется.
```

RoleContribution — самостоятельное значение: ссылка на роль, scope, source/origin, срок и поля;
это закрывает роль суперадмина с пустым `permissions()`. Источник может дать role contributions и direct grants.
Ролевые contributions разворачиваются в grants централизованно по scoped role definition.
Неизвестная удалённая роль даёт ноль прав и диагностику, а подмена scope/панели в ответе SPI — SourceError.

В Grants mode все relevant assignment источники опрашиваются независимо от порядка.
В Policy mode неиспользуемый assignment store/source не вызывается и не является dependency этого решения. Нельзя остановиться
на разрешающем источнике, если следующий сообщает ошибку. Ошибка не превращается в `null`/NotApplicable.
Unknown identity/configuration ошибки direct API выбрасывает; боевой middleware/Gate переводит их в отказ с логом.
`after` — единственный наблюдающий шаг, исключение которого не меняет уже вычисленный доступ.

### Свойства движка

| Свойство | Формулировка |
|---|---|
| P1 | Добавление валидной unconditional grant не уменьшает grant result при неизменных boundaries/policies/conditions |
| P2 | Любое Allow прошло все применимые ограничения, token cap и неизменяемую границу tenant/resource |
| P3 | Выдачи панели B не влияют на панель A; общий plugin code не хранит request state |
| P4 | При isolated решение C не зависит от выдач C2; required требует C, но сохраняет inherit-семантику внутри одного tenant |
| P5 | Одинаковые inputs, state, now и ответы внешних компонентов дают одинаковое решение |
| P6 | Разные references/scope не смешиваются в identity, SQL, cache и events |
| P7 | Истёкшая выдача не действует даже из request cache; expiresAt = now уже истекла |
| P8 | Ошибка оцениваемого before/source/policy/condition/restriction -> отказ |
| P9 | Одинаковые явные inputs через все adapters дают одно решение в authoritative режиме |
| P10 | Перенос выдачи между источниками сохраняет смысл при одинаковых scope, сроках, условиях и role definitions |
| P11 | Смена presentation prefix не меняет решения enum/full name |
| P12 | Перестановка источников не меняет решения, включая ошибку и scoped superadmin |
| P13 | Ни tenant-wide роль A, ни global ordinary grant не разрешают доступ в tenant B |
| P14 | `visibleTo` в exact режиме возвращает ровно записи, разрешённые decide на том же authority state и decisionNow |
| P15 | Условия одной grant нельзя удовлетворить полями разных grants |
| P16 | Mutation no-op, rollback и неуспешная попытка не публикуют новую committed state |

## 3. Тенант, контекст и ресурс

`TenantRef` — организация/граница данных. `AssignmentScopeRef` — проект/магазин/документ внутри неё.
`AccessScope` содержит **оба**; `context=global` значит «весь выбранный tenant».
`tenant=global` значит режим без тенанта/системный scope, а не все организации сразу.

Панель описывает `TenantPolicy::none()` или `required(Organization::class)` и зарегистрированные
`AssignmentScopeDefinition` (например ProjectScope) в `AssignmentScopePolicy`. Required tenant без выбранного tenant -> отказ,
а не fallback на глобальные права. AssignmentScopePolicy и TenantPolicy независимы.

Разрешение scope:

1. Взять явный `inTenant`/AccessRequest scope; иначе scope ресурса; иначе текущий scope **этой панели**.
2. `ResourceScopeResolver`/`ProvidesAccessScope` получает tenant и context из самого объекта.
   Если есть явный/текущий tenant, он должен совпасть с owner tenant ресурса; выбранный context должен быть связан
   с resource по resolver. Разногласие -> отказ. Тенант из URL/header — лишь кандидат, не доказательство членства.
3. Для `on: $project` или AssignmentScopeRef проверить `AssignmentScopeDefinition::resolve` → structural snapshot с identity/owner. Для AssignmentScopeRef,
   требующего модель в политике, модель загрузить доверенным resolver; отсутствующая -> отказ, без вызова с null.
4. Для resource в tenant-панели отсутствие authoritative resource resolver -> ResourceScopeMissing.
   Нельзя приписать неизвестный объект текущей организации. Для collection/create resource отсутствует;
   prospective attributes/parent relation проверяет policy/validator, включая tenant всех связанных моделей.

### Какие выдачи применяются

| AssignmentScopePolicy | Context global | Context C выбранного tenant | Непринятый AssignmentScopeRef |
|---|---|---|---|
| inherit | tenant-wide | tenant-wide ∪ C | Deny(AssignmentScopeNotAccepted) |
| isolated | tenant-wide | только C | Deny(AssignmentScopeNotAccepted) |
| required | Deny(AssignmentScopeRequired) | tenant-wide ∪ C | Deny(AssignmentScopeNotAccepted) |
| none | tenant-wide | Deny(AssignmentScopeNotAccepted) | Deny(AssignmentScopeNotAccepted) |

Глобальный ordinary grant другого tenant не включается ни в одном режиме.
`requireMembership()` контекста остаётся необязательным. Для tenant membership required-панели включён по умолчанию;
его SPI проверяет активное членство, а не наличие произвольной role grant. Освобождение tenant admin от membership
явно задаётся; принадлежность resource tenant не обходится. Старый режим «игнорировать переданный AssignmentScopeRef» снят.

Один client может принадлежать нескольким projects. Тогда resolver обязан выбрать подтверждённый project
(например из nested route) или задать явное правило exists по кандидатам. Нельзя независимо объединять permissions
по projects и остальные условия: Allow должен иметь **один целый witness** `(tenant, project, grant, conditions)`.
CRM-рецепт использует один project на client; many-to-many вариант описан в [16](16-crm-and-workflows.md).

### 3.1. Вход в интерфейс панели

Базовый admission маршрутов azguard.panel и Filament одинаков: authenticated accepted subject + valid tenant/member
+ **хотя бы одно действующее назначение зарегистрированной PHP-роли выбранной панели**. Наличие класса роли
в каталоге без назначения пользователю не достаточно. Подходят DB, GrantedAutomatically и RelationSource
role contributions; применяется scope/owner, expiry, grant conditions, common и role-specific eligibility.
Неизвестная/удалённая роль или ошибка relevant source не дают входа. Superadmin проходит как qualified role.

- Tenant-wide role подходит для выбранного tenant по своим условиям. При явно выбранном project подходят роли
  этого project и разрешённые tenant-wide contributions согласно AssignmentScopePolicy.
- Если project ещё не выбран, admission проверяет наличие **одной целой подходящей role contribution** в
  разрешённой области выбранного tenant. Так CallerRole на P1 позволяет открыть CRM и выбрать P1, несмотря на
  scopeRequired(). Это специальная проверка допуска в UI, не hasRole(on:null) и не глобальная permission выдача.
  Роли другого tenant/panel и неактивные/чужие/неподходящие projects не участвуют. Sources должны поддержать
  scoped role lookup для admission; unsupported adapter — явный отказ, не снятие фильтров.
- entry default null. Если entry задан enum case, результат его обычного authority pipeline дополнительно AND.
  Entry permission проверяется в выбранном request scope, не приписывается найденному project автоматически.
  Tenant-wide entry action можно назначить отдельно; project-level entry требует выбрать соответствующий project.
- Direct permission или PolicyOnly allow не заменяют role admission. PolicyOnly check сам остаётся без чтения
  assignments; UI admission — отдельная операция, читающая роли. Policy-only UI может определить автоматическую
  PHP MemberRole, не создавая grant store. Direct API/service checks не получают скрытого требования иметь роль.
- Admission не разрешает действия/клиентов: View/Update и SQL visibility всё равно проверяются в точном scope.
  Tenant selection до входа обеспечивает приложение; наличие роли в A не открывает B.

Один общий evaluator обслуживает middleware и Filament; его role reads используют обычные freshness/state fences.
Owning items P6.2/P7.2, future tests V73/R39.

## 4. Суперадмин

Роль `#[SuperAdmin]` / BaseRole.superAdmin() учитывает tenant/context, срок и условия как всякая роль.

| Назначение | Область |
|---|---|
| tenant=A, context=global | Всё внутри A, если AssignmentScopePolicy наследует tenant-wide |
| tenant=A, context=project:P | Только P внутри A |
| tenant=global, панель без тенантов | Вся эта панель |
| tenant=global, панель required | Не переносится в tenant автоматически |

Платформенный RootRole может пересекать tenant boundaries только как **явно разрешённая глобальная роль**
`TenantPolicy::allowGlobalRoles([RootRole::class])`. Она всё равно работает на конкретном выбранном tenant,
проверяет owner tenant/context resource, сроки, token restrictions и общие запреты. Global ordinary grants не
становятся cross-tenant. `isSuperAdmin()` сообщает о роли в выбранном scope; это не окончательный ответ о доступе.

## 5. Политики и Gate внутри проверки

| Authority | Assignment | Policy | Результат перед mandatory restrictions |
|---|---|---|---|
| Policy | не читается | true / allow Response | candidate Allow(Policy) |
| Policy | не читается | false / null / deny Response | Deny(Policy) |
| Grants | true | true / null / missing optional policy | candidate Allow(Granted) |
| Grants | false | любое (policy не вызывается) | Deny(NotGranted) |
| Grants | true | false / deny Response | Deny(Policy) |

Mode задаётся #[PolicyOnly]/#[RequiresGrant] на enum/case, не наличием policy method или DatabaseSource.
Exact PolicyOnly action нельзя назначить или поместить в BaseRole.permissions; patterns расширяются только в
Grants definitions и не становятся Authority Policy action. Dynamic action authority всегда Grants.
GrantedToAll допустим только Grants и означает accepted subject в valid scope, не все tenants/anonymous.
Declared policy binding/method missing — compile error; отсутствие optional grant-side policy — pass.
ConsultsGrants/рекурсивный fallback policy OR grants отсутствует. User/resource/service DI нативный, Response
проверяется через allowed(), denial status/message/code сохраняются. Error не превращается в Abstain/pass.

GateSource адаптирует ровно зарегистрированную external ability как policy; её результат трактуется по mode.
Он не вызывает общий Gate::before заново и не делает mapped AzGuard-owned permission recursive.
Native policy before semantics сохраняются внутри вызова policy, но не создают Grants authority и не обходят
outer mandatory boundaries. Missing mapped ability/mode — build error; unsupported Laravel adapter — qualification
failure. Owned bridge может быть обойдён более ранним host Gate::before — ограничение integration, не core.

Каждая операция вызывает business policy на актуальных observed inputs. Mode влияет также на exact query,
cache dependencies, schema/editor и mutation validation; [19 §2](19-oop-and-permission-authority.md#2-для-одного-права--один-authority-mode).

## 6. Условия выдач и общие ограничения

`GrantCondition::allows(grant, request, context)` квалифицирует **одну** grant. Все её conditions — AND;
grants — OR. Например, отдел и день недели должны подойти у одной выдачи. RoleContribution проходит conditions
до применения superadmin. Истёкшие/неподходящие grants удаляются из matchingGrants.

`Restriction` проверяет итоговый кандидат Allow: блокировка аккаунта, license, token cap, assigned-project boundary.
`matchingGrants()` для PolicyOnly allow может быть пустым; restriction обязана определить этот случай явно.
Scope/resource integrity — встроенная обязательная проверка, не отключаемая restriction с exemption.

Порядок: обязательные scope boundaries, затем restrictions панели, затем plugins; одинаковые keys — ошибка.
`before` возвращает BeforeResult::Continue/Deny; Continue не обходит policy/grants.
Все relevant before checks проходят; Deny/error не скрывается другим Continue.

## 7. Gate Laravel

```
Если явно наше full/prefixed имя -> ownership known до lookup каталога.
Если одно слово + model/class -> найти однозначное binding выбранной панели.
Если ability чужая -> null, без чтения выдач.
Если owned, но panel/tenant/resource/catalog resolve ошибочны -> false/deny Response.
d := direct pipeline (тот же tenant/context/resource и explicit authority mode)
owned permission -> d.toGateResult() (Response сохраняется)
foreign ability -> null (native Laravel продолжает свою проверку)
```

AzGuard-owned NotGranted/Policy denial не превращается в native fallback. Additive ownership mode исключён:
он нарушал explicit RequiresGrant и позволял другому authorizer компенсировать отсутствующее назначение.
Gate/direct parity относится к authoritative adapter при отсутствии раннего permissive host callback.

Ранее зарегистрированный сторонний `Gate::before` с true может остановить Laravel до AzGuard.
Пакет не может исправить это одним callback: хост обеспечивает порядок/ownership callbacks, doctor сообщает
конфликт, consumer test проверяет реальные Gate registrations. Защищённые actions в CRM вызывают direct authorize;
внешняя библиотека не получает обещания «любой Gate hook невозможно обойти».
Два Filament resources одной модели проверяют явный resource permission, не угадываются по классу модели.

## 8. Кэш и консистентность

`StateToken = {storageId, panel, incarnation, version, generation, fingerprint}`.
Scope не зашит в panel definition; ключ кэша также включает subjectRef, tenant, contexts, source partition и codec version.
`fingerprint` содержит нормализованные definitions, bindings/config, deployment build id и plugin prefixes.
Хэш одних FQCN не замечает изменения метода: при смене кода меняется build id, catalog cache пересобирается,
Octane/queue workers перезапускаются. Registry static; opt-in dynamic permission catalogue — scoped overlay по DB version; BaseRole definitions только code build.

### Чтение DB authority

Этот раздел применяется только если selected Grants path потребляет DatabaseSource. PolicyOnly не выполняет
его state reads, а code/relation-only path использует свои revisions + CodeStateToken (16 ниже).

Холодная загрузка: fresh primary `T_before` -> scoped dynamic-action catalogue/grants -> fresh primary `T_after`.
При равенстве токенов набор пригоден; при различии **весь DB набор** перечитывается, до 3 попыток,
затем Deny(ConsistencyError). Нельзя поместить смесь версий в кэш под последним token.
State reads для этого fence не берутся из request memo или старого repeatable-read snapshot приложения.
Warm cache должен иметь точное T совпадение и проверенные абсолютные сроки.
Динамический catalog lookup выполняется в том же validated чтении; статический miss не означает отказ
до проверки dynamic overlay выбранного tenant.

При refresh=request warm DB authority token переиспользуется в request/job. Если новый cold load
обнаружил более новую version, request memo продвигается и старые entries очищаются; старый token не выдаётся
за исторический снимок, которого DB API не умеет читать. При refresh=check fresh state нужен перед каждым check.
В собственной незакоммиченной mutation кэш обходится; tentative данные не публикуются ни в store, ни в общий memo.
В приложенческой транзакции со старым snapshot строгий режим требует fresh authority connection либо явного
совместного transaction protocol; если обеспечить его нельзя — configuration error, не ложная гарантия fresh reads.

**Гарантия отзыва:** при primary/fresh authority новая request/job, начавшая проверку после root commit отзыва,
не использует отозванные DB grants. При refresh=check это относится к следующей проверке текущего request.
Уже начатая проверка может закончить со старым набором. Это не отменяет защищаемое действие (§14).
`reads=default` с репликами даёт окно replication lag и **не гарантирует** freshness даже при version fence
на одной отстающей реплике.

### Что можно кэшировать

| Компонент | Правило |
|---|---|
| Stable source | Межзапросно лишь при revision/expiry contract; DB version покрывает только DB authority |
| Request source | Только request/job; срок проверяется при каждом check |
| Volatile source | Каждый check, включая отзыв токена/внешний API; timeout -> отказ |
| Policies, hooks, restrictions, GrantCondition | Каждый check; final Allow не кэшируется по одному StateToken |

У relation source/автоматической роли данные хоста не меняются через Storage::mutate. Они Request по умолчанию;
Stable требует dependency revision или touch при **каждом** влияющем изменении, включая bulk/delete/rollback.
Полная гарантия мгновенного отзыва membership требует fresh/Volatile membership adapter либо общей revision authority.
Одно permission revoke удаляет лишь конкретный DB вклад: тот же доступ может оставаться из другой роли/источника.
Жёсткое прекращение доступа — общая restriction/suspension, а не воображаемая deny grant.

Повторный grants read может стоить 0 запросов; полная проверка с live membership/policy по-прежнему выполняет
их собственные запросы. `validUntil <= now` инвалидирует и request memo, не только cache store TTL.
Reset/restore меняет incarnation; версии не сбрасываются на старое значение с прежним cache namespace.

## 9. Пакетная оценка

`decideMany` группирует по `(storageId, panel, subject, tenant)`, contexts режет пачками по 100.
DB данные **всех пачек группы** читаются между одним T_before/T_after; retry перечитывает все пачки.
Результаты возвращаются в исходном порядке. `now` один на batch; сверхбольшой batch делится вызывающим кодом,
чтобы deadline не устаревал до действия. Decisions разных panels не имеют одного общего StateToken:
`DecisionSet::states()` — map по storage/panel; каждая Decision несёт свой token/scope.

Policies/restrictions/conditions вызываются для каждого request, включая два экземпляра одной модели
с разными prospective changes; memo по `(permission, model id)` недопустим.
`BatchRestriction` разрешён при контракте, равном поэлементному check. Один DB token не является snapshot
удалённого LDAP, токена, хостовых membership и resource data: их согласованность объявляет adapter.

## 10. Видимость (`visibleTo`)

`visibleTo` означает **окончательный** доступ к строкам, не «похожие grants». Строгий exact режим по умолчанию:
если компоненты этого permission не дают эквивалентную query semantics — VisibilityNotSupportedException.
Один warning о неполноте не разрешает выдачу клиентских данных.

Query plan повторяет scalar semantics: tenant owner AND scope integrity AND restrictions AND
(Policy mode: policy allow; Grants mode: qualified grants/scoped superadmin AND policy not deny).
Before checks только constraints. Policy null/true/false, exemptions и grant conditions
компилируются явно; произвольный PHP callback в SQL автоматически не переводится.
`FiltersQueries` описывает grants; `FiltersAccessQueries` описывает итоговую policy/restriction/condition логику.
Query descriptor policy/before содержит раздельные allow/deny/abstain predicates, conditions получают
конкретную Grant/RoleContribution; SQL NULL обрабатывается явно.
Все relevant contributors должны иметь exact adapter, либо на request детерминированно дать empty/pass/deny.
Неизвестный volatile ответ не заменяется false или true ради удобного SQL.
Restriction с `FiltersAccessQueries` в списке компилируется всегда: `appliesTo()` не вызывается, применимость кодируется
в `predicate()` (`AccessPredicate::pass()` для неприменимого). Ограничение без адаптера пропускается по `appliesTo()=false`
только если ответ не зависит от ресурса.

- Predicate применяется **до** count/order/limit/pagination/export/aggregate; существующий WHERE не ослабляется
  внешним OR: `(host filters) AND tenant AND (source1 OR source2) AND restrictions`.
- Scope для Client строится через project relation, а не сравнением client.id с project context id.
- Superadmin убирает только grant-предикат; tenant, token, resource integrity и обязательные ограничения остаются.
- Нет субъекта -> пусто; явный неподдерживаемый scope -> ошибка/отказ. Auth глобально не читается.
- `view_any` открывает страницу, `view` фильтрует её строки. Update/delete/export permissions проверяются отдельно.
- SQL joins host/resource и grant tables возможны только при одном SQL connection; cross-connection ->
  VisibilityNotSupported, либо явный bounded id adapter с указанными лимитами и consistency contract.
- QueryResult вычисляется на фиксированном decisionNow; данные SQL используют свой snapshot.
  При требовании совпадения нескольких count/data запросов вызывающий код берёт transaction snapshot.

Для arbitrary policy есть отдельный `Visibility::candidates()` — **внутренний** grants prefilter, не безопасный
список. Grants prefilter неполон, если policy/before может разрешить без grants: в этом случае допустим только
полный bounded host dataset выбранного tenant или эквивалентный exact adapter. Consumers проверяют весь
bounded candidate set до формирования ответа; total считается по разрешённому набору. Нельзя filter готовую страницу и сохранять исходный total; unbounded export этим способом запрещён.
Предикаты CRM продублированы scalar/query адаптером и сверяются генеративными тестами P14.

## 11. Объяснение

`explain` выполняет тот же pipeline, фиксирует выбранные panel/tenant/context/resource, role and grant origins,
conditions, policy Response, constraints, now и state. Список доступных permissions не выдаётся за решение policy.
Skipped component помечается skipped, не pass. Trace не перепрашивает источники и не пишет access mutations.
Причины для внешнего HTTP ответа могут скрываться (404 для чужого объекта); полная trace защищена правом диагностики.
Credentials внешних систем, токены и весь subject object в trace не сериализуются.

## 12. Среда исполнения

| Среда | Контракт |
|---|---|
| FPM | scoped request state; defaults ставятся после auth и route binding |
| Octane | immutable registry; sources/plugins не захватывают пользователя/tenant из boot-time контейнера |
| Queue | explicit panel + tenant + context/resource ref; rehydrate и authorize в job, не доверять ранее сохранённому Allow |
| Sync queue | стек scope восстанавливается после job, не сбрасывает request наружу |
| CLI | tenant required задаётся --tenant; actor system с причиной; нет случайного Auth default |
| Fibers | только явный AccessRequest/immutable SubjectAccess; ambient scope не поддержан |

## 13. Как новые контракты закрывают старые дефекты

N01/P01/P14 закрывают scoped role contributions и запрет bare wildcard; N04/P04 — exact visibility;
N05/N09/P09 — единый resolver и conflict detection; P07 — единый codec;
N07/P06 — tenant/context policy на панели; N08/P08 — DatabaseSource с одним scoped query;
N11/P11 — общий mutation pipeline и root after-commit; N12/P02 — key вместо FQCN;
C02 — scope stack finally; C03 — primary/fresh reads; C04 — mutation + version;
N17/P10 — scoped memo с expiry; N19/P03 — authoritative adapter и один policy path.
Новые архитектурные находки H01–H14 и границы доказательств — [evidence](evidence/design-review.md).

## 14. Проверка и защищаемое действие

`authorize()` не резервирует доступ. Между Allow и update resource могли перенести в другой tenant,
membership отозвать или grant изменить. Для чувствительной записи хост использует одну transaction на общем
connection: lock panel_state перед authority check, затем согласованные locks/revisions membership, project,
resource, затем update и commit. Это тот же lock order, что у revoke. Actor/grant updates не должны иметь обратный порядок.
Если изменение связано с несколькими панелями, их state locks сортируются до resource locks.

При разных connections/внешнем authority такая атомарность недостижима одним API AzGuard. Интеграция фиксирует
допустимую гонку или реализует собственный command/revision protocol. Ни Gate, ни StateToken, ни batch check
не дают автоматической linearizability DB записи. Request-based read guarantees сформулированы только в §8.

## 15. Настраиваемая context eligibility

[18](18-contexts-and-runtime-inputs.md) — дополнение D75–D78. Common predicates действуют до любого Allow,
включая hook/policy/superadmin. Role predicates — AND внутри квалификации одной RoleContribution до OR всех
выдач; direct role-less grant не имеет скрытого BaseRole. Фильтр сужает область, не выдаёт permission.
Global false -> AssignmentScopeIneligible; query error -> AssignmentScopeFilterError. False role binding удаляет только её вклад.

Один Eloquent context query plan используется для scalar EXISTS, batch, exact visibleTo, Assignment directories
и final validation; user/BaseRole/actor/proposed fields передаются явно. Access phase одна для record/list/count/
export/queue. Inspection/Revocation не требуют runtime активности grant, но требуют actor authority по scoped row.
Query callbacks не могут заменить outer owner/correlation; неподдержанная SQL shape не выдаёт широкий fallback.
Context host dependencies заявляют freshness/revisions; query callbacks читают live либо declared input snapshot,
а final Allow не кэшируется только по DB authority version. Cache recipes не сериализуют runtime callbacks/models.


## 16. Code state и assignment state

StateToken версии хранилища не обязателен для каждого права. CodeStateToken содержит panel/buildId/fingerprint
compiled PHP catalogue. Decision.state(): CodeStateToken|StateToken: Grants с DatabaseSource возвращает consumed
DB state с code fingerprint; PolicyOnly и code/relation-only Grants — CodeStateToken. Токен не версионирует
host Project/User/policy/remote data; consumed dependency revisions/Volatility учитываются отдельно в frame/trace.
PolicyOnly static mode определяется до вызова runtime DB source, поэтому сбой assignment connection не ломает
его policy business check; сбой business connection всё ещё deny. Dynamic unknown lookup не выдаётся за PolicyOnly.
Mixed decideMany batches partition по panel/mode/required store/dependencies, один now; map состояния использует
keys code:<panel>:<buildId> и storage:<storageId>:<panel>, values typed union, не fake version=0.
PanelAccess.state()/touch() остаются explicit DB management calls, не prerequisite policy-only authorize.
Compiled active build fence обеспечивается host deployment/runtime lifecycle adapter независимо от assignment DB.
Несогласованный rollout/старый worker нельзя объявить serializable PHP deploy; strict writes require build/revision
check перед записью и согласованный host protocol. In-flight requests не отменяются автоматически.


Optional RequiresGrant veto объявляет PolicyBinding(action, policy class); метод обязан иметь #[Decides(action)].
Missing declared class/attributed method — compile error; имя метода можно менять с сохранением атрибута. PolicyOnly binding всегда обязателен;
folder/PolicyFor/Decides pairing допустим при однозначной цели. Метод не переименовывается в silent no-policy pass.
