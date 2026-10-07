# 17 — Реальная CRM-приёмка готовности пакета

**Обязательное требование владельца:** реализовать много сложных интеграционных кейсов, подтверждающих,
что пакет подходит для реальных проектов. Перечень ниже — план приёмки, **не отчёт passed**.
Owning item — P8.7; связанные contracts — D74–D79, [18](18-contexts-and-runtime-inputs.md), [14](14-verification.md).
Приложенная SQLite design model/проверка Markdown не заменяет эти тесты.

## 1. Реальный стенд

`tests/Acceptance/Crm/` — минимальное host-приложение через принятый Testbench/Pest harness, настоящие package
providers/migrations/container/HTTP/Gate/events/queues/Eloquent/Filament adapters. Отдельный consumer fixture
проверяет опубликованный public API и сгенерированные файлы без ссылок на Internal namespaces пакета.

Пакетные authorization/storage/context components не мокируются. Настоящая test DB содержит business tables
organizations, membership, cities, regions, projects, project_members, clients, external_installations/mappings,
и таблицы ролей/выдач пакета. Host составные FK проверяют принадлежность project tenant. Внешний transport можно
подменять Http::fake с завершёнными/частичными/ошибочными ответами; mapping/sync/core используют реальный код.
Audit/CRM plugins устанавливаются обычным Laravel provider и настройками; queue serialization используется реально.
Fixture builders создают данные, а не вычисляют ожидаемое решение повторением production algorithm.

Две панели `crm`/`backoffice`, обе используют web authentication guard, но отличаются definitions/settings/plugins.
Организации A/B; города Казань/Самара; регионы R1/R2. Люди: Анна (A/B, Казань), Борис (A, Самара),
администратор Дарья (A), outsider (без membership), system import actor. Static seller/caller/analyst/admin,
code-defined city-seller/campaign-lead classes; direct grants, relation membership и import grants с разными origins.

| Проект | Tenant / city / active | Клиенты |
|---|---|---|
| P1 | A / Казань / true | C1 нормальный, C2 do_not_call |
| P2 | A / Самара / true | C3 нормальный |
| P3 | A / Казань / false | C4 нормальный |
| P4 | B / Казань / true | C5 нормальный |
| P5 | A / Казань / true, R2 | C6 нормальный; дополнительные clients для pagination |

Анна имеет seller на P1 и analyst на P2; в B analyst на P4. Seller binding ограничен city_id пользователя,
общий ProjectScope — is_active. Борис получает SellerRole, whose SellerProjects — явный PHP filter. Для отдельных
кейсов добавляются direct grant и scoped superadmin; каждый case описывает собственный seed delta.
Повтор local external project id в A/B нормализован в разные host refs; не создаётся неверный ambiguous Project::id.
Clock фиксирован, экспирация проверяется без sleep. Параллельные workers/processes синхронизируются barriers.

## 2. Кейсы возможностей и отказов

Каждый R-кейс содержит Arrange/Act/Assert, положительный контроль и assertion фактического результата.
Имена R01–R68 постоянны; соседние unit tests могут проверять маленькие части, но не заменяют эту приёмку.

### Панели, модели и tenancy

| # | Действие | Обязательный результат |
|---|---|---|
| R01 | Анна guard('crm')->inTenant(A) читает C1, C3; tenant B читает C5 | C1/C3/C5 доступны соответствующими ролями; чужая организация не смешивается |
| R02 | CRM grant проверяют через guard('backoffice') | отказ; backoffice positive grant отдельно даёт доступ |
| R03 | guard('crm')/azguard()->guard('crm') при web Auth; native guard(guarded: ['secret']), mergeGuarded, fill | Auth/default/subject не переключились; string wrappers immutable; array возвращает модель и сохраняет native mass-assignment защиту; custom guard override проверен consumer fixture |
| R04 | Один User instance, независимые wrappers A/B; nested вызовы и exception | ранее созданные wrappers/ambient hints не изменились |
| R05 | Полное имя backoffice:action внутри явно выбранной crm; prefix/enum conflicts; crm resourcePrefix id/custom/false; shared enum | explicit conflict отклонён; enum+guard неизменен при смене prefix; shared enum без guard ambiguous; hint маршрута не подменяет явную панель |
| R06 | Outsider, удалённое membership, неизвестный tenant или forged resource owner | отказ через все поверхности; наличие policy allow/admin flag не исправляет boundary |
| R07 | Project выбран из A, payload Client с tenant B либо project B | create/update отвергнуты сервером и FK; нет partial protected write |
| R08 | Два providers/mappings используют один local external id | refs/clients/grants/queries разделены по installation/tenant; нет resolver fallback |

### Конфигурируемые контексты и роли

| # | Действие | Обязательный результат |
|---|---|---|
| R09 | Project P3 inactive; дать grant, policy allow, before allow и superadmin по очереди | existing contributions не разрешают C4; Assignment в inactive проект отклонён |
| R10 | seller только P2 (другой city), без analyst | view/update C3 отказ; grant не создаёт доступ вопреки binding |
| R11 | seller P1 и analyst P2 одновременно | seller город не ограничивает analyst: C1/C3 view; update C3 отказ |
| R12 | Переставить grants/sources/plugins при одинаковых inputs, добавить independent role | ответы совпали; отрицательная ветка не испортила независимую разрешающую |
| R13 | На одном Project несколько ролей/назначений с разными region fields и typed RegionCondition | Одни grant fields не используются другой contribution; city/region удовлетворены у одного witness |
| R14 | Два PHP role classes с разными typed Project filters назначены через DB/relation | Один ProjectScope; actual BaseRole/target/actor конкретной ветки, никаких role models |
| R15 | Изменить user city/project active/grant condition fields; новые операции | host freshness соблюдена; DB grant version не выдаётся за актуальность host state |
| R16 | Direct grant без роли; common callback и callback, требующий обязательную роль | common eligibility действует, role=null явно; отсутствие role не порождает случайную ORM модель |
| R17 | isSuperAdmin роль с пустыми permissions и city binding | действует только подходящий scope/binding; inactive/чужой tenant/token cap сохраняют запреты |
| R18 | Допустимые whereHas/local scope/группированный OR; попытки outer OR/from/connection/new builder | сложный допустимый фильтр работает; owner/common predicates не ослаблены; unsupported форма отклонена |
| R19 | External AssignmentScopeDefinition.resolve(ref), model=null, timeout и нет exact query adapter | scalar contract explicit; timeout deny; exact list unsupported, не все clients |
| R20 | AssignmentScopeResolver/owner/query service исключение, unknown binding/profile/type | отказ с reason/trace; нет fallback на global context и partial Allow |

### Политики, динамика и администрирование

| # | Действие | Обязательный результат |
|---|---|---|
| R21 | Caller Update C1/C2, ClientPolicy do_not_call=false/true | C1 update да, C2 нет; view обеих может оставаться доступен |
| R22 | PolicyOnly/RequiresGrant: policy null/true/false/Response и отсутствие assignment | Policy true разрешает только PolicyOnly; RequiresGrant без assignment deny NotGranted без вызова policy, null pass только при grant |
| R23 | Create opt-in dynamic action, direct grant Boris на project; те же code роли A/B | Action/assignments tenant-scoped; роль не создаётся; без флага dynamic create rejected |
| R24 | PHP роль возвращает context с несколькими typed filters; активировать новый build | Filters внутри binding AND; same scalar/list/editor metadata; stale build/form rejected |
| R25 | Raw UI request пытается создать/редактировать role definition/filter class/operator/PHP | Definition mutation endpoints отсутствуют/отклоняют; данные/versions неизменны; valid assignment проходит |
| R26 | Admin actor назначает роль Анне, его собственный город отличается | target user фильтр использует Анну; actor delegation использует admin, не target |
| R27 | Менеджер с ограниченным delegation пытается дать admin/wildcard/cross-project role | отказ escalation; разрешённая конкретная выдача проходит; actor reason в журнале |
| R28 | Deactivate/expire/delete context, затем admin revoke | запись доступна authorised admin read API и отзывна; runtime eligibility не блокирует revoke |
| R29 | Code role/mode/context deployment одновременно с новым assignment | Active build/host fencing предотвращают смешанную запись; unknown removed key zero authority/cleanup |
| R30 | Update grant fields/expiry после changing pipe; nested transaction rollback/no-op | final validation повторяется; rollback отменяет строки/version/audit/events; no-op не bump |

### Списки, UI и полноценная работа с клиентами

| # | Действие | Обязательный результат |
|---|---|---|
| R31 | clients.view exact list для Анны A/B | множество ids равно независимым fixtures C1/C2/C3 в A и C5 в B с учётом seed; чужие rows отсутствуют |
| R32 | clients.update exact list при mixed seller/analyst/policy | только разрешённые update ids, C2/C3/C4/C5 в A исключены; policy NULL SQL обработан |
| R33 | Pagination/order/search/total на проектах, где первые rows запрещены | корректные разрешённые страницы/total; нет filter-after-paginate/подсчёта запрещённых rows |
| R34 | Export/aggregate/widget/global search/relation attach/bulk action | те же boundaries; ни ids, ни count, ни summary не раскрывают чужие данные |
| R35 | Нет exact adapter у policy/hook/filter; grants candidates и policy-only allow | exact list ошибка; bounded fallback не теряет allowed вне grants и не отдаёт непроверенные строки |
| R36 | Context autocomplete с target seller/analyst, разные города/тенанты | показываются варианты Assignment нужного subject/role, не текущего admin; LIMIT после eligibility |
| R37 | Прямая подстановка grant/role/project id из B в A UI, stale tenant form | отказ IDOR; membership/options пересчитываются сервером; форма не перенесла старые fields |
| R38 | RoleResource catalogue/SubjectGrants editor/PolicyOnly badge | Code roles/filters read-only; только RequiresGrant assignments editable; raw forged definition change rejected |
| R39 | Реальные HTTP controller/Gate/@can/middleware/Filament; admission с default entry=null/optional enum entry | direct/authoritative action parity; без роли admission deny даже с direct grant/PolicyOnly allow; CallerRole на eligible P1 входит только в A, действия остаются scoped; expired/inactive/чужая роль deny; optional entry AND и superadmin veto; ранний сторонний Gate::before фиксируется consumer test |
| R40 | Лид обзвона: открыть список → карточка → отметить звонок → запрет do_not_call → export | реальные Client writes разрешены лишь своему project; refusal не создал запись/событие звонка |

### Источники, плагины и интеграции

| # | Действие | Обязательный результат |
|---|---|---|
| R41 | permissions([Enum, DatabaseSource, 'ldap']) + повтор enum/discovery/plugin contributions | одно canonical action, correct source order; bad input/source collision/writer conflict rejected |
| R42 | Relation membership + manual grant + external origin на одно право; revoke одного | оставшиеся contributions продолжают разрешать; origins/relation authorities независимы |
| R43 | Complete import/webhook повтор, смена provider role, partial page/timeout | idempotent complete sync; partial не revoke всё; scopes/mappings/revisions разделены |
| R44 | Source error после разрешающего source/superadmin, разные source orders | отказ при любой перестановке, reason source_error; нет stale successful import |
| R45 | CRM plugin named typed CrmModels/projects/membership/directory в двух panels | Каждый plugin использует свои валидированные model contracts; config/runtime данные не пересекаются |
| R46 | Concrete plugin factories разной сигнатуры, общий BasePlugin, shared recipe binding | PHP class load/typed params/named args valid; base не навязывает make; recipes не мутируются |
| R47 | Runtime capability plugin получает user/BaseRole/proposed grant/actor/scope/phase | inputs соответствуют текущей ветке; сервис DI работает без global model rebinding |
| R48 | Plugin disable/missing requires/duplicate id/prefix collision, повтор worker boot | предсказуемые build errors; listeners не дублируются и не остаются при новом lifecycle |
| R49 | Typed plugin secret service refs и schema/cache/trace/export | Секреты/live models/container не раскрываются; deterministic metadata+build fingerprint |
| R50 | External consumer со своим AssignmentScopeDefinition, AssignmentScopeFilter и directory | Public SPI достаточен; local scope query() свежий, resolve загружает один row с owner, filter не меняет structural lookup; external resolve не требует Eloquent; enum actions/code roles без Internal namespaces |

### Состояние, workers, масштаб и релиз

| # | Действие | Обязательный результат |
|---|---|---|
| R51 | TTL/request memo/absolute expiry на границе now | expired grant не действует, в том числе scoped admin; поддержанные retry/clock без sleeps |
| R52 | Revoke+root commit, fresh primary/check refresh, replica lag | гарантия primary соблюдена; documented replica/request windows не маскируются passed строгого режима |
| R53 | Async export job A, затем dequeue при Auth/context B/снятом grant | job использует explicit refs A, fresh authorize; B не подменяет subject/role и revoked export запрещён |
| R54 | Octane-style один application instance, два запросa A/B и два jobs; failures cleanup | scoped adapters и memo сброшены; callbacks получают свежие inputs, stale CurrentUser не захвачен |
| R55 | Два concurrent executions/fibers с разными user/tenant/role в одном worker | explicit inputs изолированы; scoped binding само по себе не выдано за fiber isolation |
| R56 | Consumer generated panel/permission/policy/plugin stubs + cached catalog reload | paths/method guard/permissions/config/metadata корректны; live/cache решения совпали |
| R57 | Filter/role/policy code change/build id, DB restore и захваченный runtime object | Code token/store incarnation не смешиваются; старый cached catalogue не воскрес; invalid capture/recovery rejected |
| R58 | Большая CRM: 10k clients, 100 scopes, multi-role batch scalar/list/total | set-based SQL/EXPLAIN; query count не растёт на каждый Client; plan/params/chunks budget явен и все ids учтены |
| R59 | Actual concurrent grants/action delete/deploy revision и host Project transfer | Barriers + package locks + host build/ownership protocol; никакой stale protected write |
| R60 | Qualification на заявленных engines/framework/consumer combinations | actual migrations/unique/collation/SQL/fixtures resolve; неподдержанная/непройденная комбинация не объявлена supported |

### Разделение authority и строгая ООП-конфигурация

| # | Действие | Обязательный результат |
|---|---|---|
| R61 | PolicyOnly static action, назначение DB недоступно/spy connection abort-on-read; host DB доступна | policy access работает без одного grant/state/catalogue DB чтения; state CodeStateToken |
| R62 | RequiresGrant без grant, policy true, before Continue, затем дать role/direct grant | до назначения deny, после allow; false policy deny даже scoped superadmin |
| R63 | PolicyOnly grant попытка через trait/CLI/HTTP/UI/source/role list и wildcard | exact assignments rejected, source misuse detected; wildcard не разрешает PolicyOnly; no DB write/version |
| R64 | Mixed panel policy-only/view-own и assigned update, correlated tenant/project, exact lists | каждый action использует свой mode; бизнес SQL допустим; policy host error deny, assignment-only outage не ломает PolicyOnly |
| R65 | Enum definitions + DB assignment без dynamicPermissions, затем runtime action creation | enum grant работает без копии enum в permissions; dynamic create rejected до opt-in; action mode immutable Grants |
| R66 | Remove/rename code role и изменить mode Grants ↔ Policy; старые grants и workers | unknown/stale build deny; authorised cleanup работает; FormerKeys migration explicit; old assignment не переопределяет новый mode |
| R67 | Wrong named factory arg/type, abstract/non-Model class, project descriptor/model mismatch; policy method rename/remove/remove Decides | actionable boot errors; валидный consumer с собственными typed DTO/filter DI работает; no options parser; rename с сохранением Decides работает, потеря declared implementation/attribute — compile error |
| R68 | Mixed decideMany PolicyOnly/Grants/code-only relation, two panels, one captured now | только consumed DB dependencies fenced; typed state map no fake version; original order/results сохранены |

## 3. Как проверять результаты

Для ключевых потоков R01/R11/R21/R31–R40/R45/R53 задаются заранее ожидаемые ids, UI/HTTP results,
созданные/несозданные Client/Call/Grant rows, state version и committed events. Дополнительно whole allow-id
множество сопоставляется scalar/query на **одних authority inputs и now**. Генеративная матрица полезна после
реальных CRM fixtures, но oracle не вызывает тот же compiler/decision helper, что production code.

Проверяются message/status/trace reason отдельно от bool, correlation subject/actor/tenant/role/origin и отсутствие
секретов. Positive контроль предотвращает «все запрещено» как ложное прохождение. Убрать городную/common/context
проверку или поменять AND ветки на OR должно ломать соответствующие acceptance cases; mutation test optional,
обязательны содержательные assertions, а не проверки числа вызовов моков.

Same-frame scalar/query равенство не проверяется на двух разных snapshots во время конкурентных изменений.
Для concurrency отдельно явно заданы порядок операций и гарантия freshness/host lock. SQL EXPLAIN/query budgets
собираются на реальных заявленных СУБД; SQLite green не доказывает PostgreSQL/MySQL concurrency/DDL.
UI/R39 не сводится к fake Gate. Профиль, callback и plugin из consumer fixture исполняются реально.

## 4. Порядок внедрения и критерий готовности

1. P8.7 создаёт fixture app/таблицы/данные/consumer scripts рано; cases добавляются вместе с owning implementations.
2. P1.6/P2.1/P2.4/P2.7/P2.8/P4.1/P4.6/P4.8/P4.12/P5.3/P5.5/P6.8/P7/P8.4 предоставляют необходимые capability contracts.
3. Кандидат stable проходит **все R01–R68** в соответствующих surfaces; matrix variants относятся только к реально
   объявленным комбинациям. При неподдержанной capability должен проходить её явный unsupported case.
4. В CI/qualification report: case id, variant engine/framework, duration/query budget, actual passed/failed/blocked,
   artifacts SQL/EXPLAIN/trace/worker race и ссылка на изменение. Skipped/UI-not-wired не равно passed.
5. Заключение «готов для реальных проектов» сопровождается этой evidence matrix и ограничениями. Число unit tests,
   успешный Markdown validator или один happy path не дают такого заключения.

Тесты выполняются только на доказанно изолированной test DB/files/queue. Для concurrency используются отдельные
connections/processes с barriers, не общий transactional test rollback между процессами. Production credentials
не нужны; внешние transport fakes не подменяют семантику source/plugin/context/mutations.
