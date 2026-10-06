# CRM scalar and exact visibility acceptance

Стенд Testbench/Pest использует настоящий Authorizer, providers, Eloquent, Storage и SQLite `:memory:`. Время: 2026-10-06 12:00 UTC. Панели crm/backoffice; A/B; P1–P5; C1–C6; Анна, Борис, Дарья и outsider. Назначения создаются через Storage::mutate. Клиент имеет составной FK project+tenant. Ожидания — литеральные ids/reasons, положительные контроли; фильтры проверяются также намеренными поломками на scratch-копии.

Запуск: `DB_CONNECTION=sqlite php -d memory_limit=1G vendor/bin/pest tests/Acceptance/Crm`. Acceptance подключён к tests/Pest.php и phpunit.xml, поэтому обнаруживается composer test.

`GREEN scalar` означает завершённый срез Authorizer этого пункта. `partial` означает пройденный локальный срез и указанный будущий остаток. `future` не заявляет прохождение. P4.23 квалифицирует exact lists/count/pages и отдельный VisibilityExplainTest на PostgreSQL/MySQL; HTTP/UI/queues и установленный внешний consumer остаются будущими поверхностями.

DB assignment refresh — Check. RelationSource использует Request-volatility: изменение pivot проверяется в новом request scope (Laravel forgetScopedInstances); host city/active читаются заново и при одинаковом storage token. Boundary может завершить отказ до чтения assignments с CodeStateToken; этот токен не сравнивается с StateToken как доказательство версии host.

| Кейс | Проверенная поверхность / тест | Статус | Owning item и остаток |
|---|---|---|---|
| R01 | [TenancyTest.php](TenancyTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R02 | [TenancyTest.php](TenancyTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R03 | — | future | —; **future:** P5.1 trait/SubjectAccess; P6.1 facade; P6.2 HTTP/queue boundary |
| R04 | — | future | —; **future:** P5.1 trait/SubjectAccess; P6.1 facade; P6.2 HTTP/queue boundary |
| R05 | — | future | —; **future:** P5.1 trait/SubjectAccess; P6.1 facade; P6.2 HTTP/queue boundary |
| R06 | [TenancyTest.php](TenancyTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R07 | [TenancyTest.php](TenancyTest.php) | partial | P4.15: host FK; **future:** P5.2/P5.3 server writes/delegation |
| R08 | — | future | —; **future:** P5.2/P5.3 assignment/delegation/revocation pipeline |
| R09 | [ContextRolesTest.php](ContextRolesTest.php) | partial | P4.15 Access inactive common filter; **future:** Assignment phase revalidation P5.2/P5.3 |
| R10 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R11 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R12 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R13 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R14 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R15 | [FreshInputsTest.php](FreshInputsTest.php) | partial | P4.15 host freshness; P4.8 raw cache; P4.23 list sameT GREEN; **future:** Grant-field write/revalidation P5.3; job integration P6.10 |
| R16 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R17 | [ContextRolesTest.php](ContextRolesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R18 | [NativeFiltersTest.php](NativeFiltersTest.php) | partial | P4.15/P4.19 scalar native shapes; P4.23 exact whereHas/local/grouped OR and rejected mutations GREEN; **future:** остальные surfaces строки нормативной matrix |
| R19 | [ExternalScopeTest.php](ExternalScopeTest.php) | partial | P4.15/P4.19 external scalar model=null; P4.23 exact unsupported before Client pagination GREEN; **future:** остальные surfaces строки нормативной matrix |
| R20 | [NativeFiltersTest.php](NativeFiltersTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R21 | [PoliciesTest.php](PoliciesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R22 | [PoliciesTest.php](PoliciesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R23 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R24 | — | future | P4.19/P4.23 typed AND/deployment scalar-list; **future:** P5.5 schema/editor metadata; P7.4 stale form; P8.7 consumer |
| R25 | — | future | —; **future:** P7.3/P7.4 definition mutation absence; assignment P5.3 |
| R26 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R27 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R28 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R29 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R30 | — | future | —; **future:** P5.2/P5.3 real assignment/dynamic/revocation/pipe writes; consumer concurrence P8.7 |
| R31 | [VisibilityTest.php](VisibilityTest.php) | GREEN exact | P4.23: independent literal A/B view IDs and scalar/list/count parity |
| R32 | [VisibilityTest.php](VisibilityTest.php) | GREEN exact | P4.23: update literal IDs, mixed seller/analyst, policy true/false/null |
| R33 | [VisibilityTest.php](VisibilityTest.php) | GREEN exact | P4.23: host search/order, forbidden first rows, full honest total and pages |
| R34 | — | future | —; **future:** P7.2/P7.4 export/widgets/search/attach/bulk surfaces |
| R35 | [VisibilityTest.php](VisibilityTest.php), [NativeFiltersTest.php](NativeFiltersTest.php) | GREEN exact/bounded | P4.23: unsupported components before resource execution, complete policy universe outside grants, oversize rejection |
| R36 | — | future | —; **future:** P5.5 schema/options; P7.3/P7.4 assignment editor/IDOR/read-only definitions |
| R37 | — | future | —; **future:** P5.5 schema/options; P7.3/P7.4 assignment editor/IDOR/read-only definitions |
| R38 | — | future | —; **future:** P5.5 schema/options; P7.3/P7.4 assignment editor/IDOR/read-only definitions |
| R39 | — | future | P4.11 Gate/@can slice; **future:** P6.2 HTTP/admission; P7.1/P7.2 Filament; P8.7 early foreign Gate consumer |
| R40 | — | future | —; **future:** P7.2/P8.7 full Client/Call protected write workflow; P5.2 mutation policy |
| R41 | — | future | P4.18 real mixed sources/canonical code role slice; **future:** P8.7 plugin/ldap CRM consumer; canonical catalog already P2 |
| R42 | [SourcesTest.php](SourcesTest.php) — independent DB/relation read witnesses | partial | P4.18/P4.15 relation+manual contributions read; **future:** P5.3 revoke remaining source; P6.10 external import origin |
| R43 | — | future | —; **future:** P6.10 complete external sync/webhook/timeout protocol; P8.7 consumer |
| R44 | [SourcesTest.php](SourcesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R45 | [BuildInputsTest.php](BuildInputsTest.php) | partial | P4.15 local real configured panels/plugin inputs slice; **future:** P8.7 full consumer plugin reload/factory/model contracts, P2 plugin build already closed |
| R46 | [BuildInputsTest.php](BuildInputsTest.php) | partial | P4.15 local real configured panels/plugin inputs slice; **future:** P8.7 full consumer plugin reload/factory/model contracts, P2 plugin build already closed |
| R47 | [RuntimeInputsTest.php](RuntimeInputsTest.php) | partial | P4.19/P4.15 actual inputs/DI Access; **future:** P5.2/P5.3 proposed Assignment/Revocation; P8.7 consumer |
| R48 | [BuildInputsTest.php](BuildInputsTest.php) | partial | P4.15 local real configured panels/plugin inputs slice; **future:** P8.7 full consumer plugin reload/factory/model contracts, P2 plugin build already closed |
| R49 | [DiagnosticsTest.php](DiagnosticsTest.php) | partial | P4.10/P4.15 trace/cache secret and local runtime inputs; **future:** P5.5 schema; P7.4 export; P8.7 plugin consumer; **future:** P4.10 trace/full diagnostics, P5.5/P7.4/P8.7 |
| R50 | [ConsumerSpiTest.php](ConsumerSpiTest.php), [ExternalScopeTest.php](ExternalScopeTest.php) | partial | P4.15 local public SPI resolve/owner/native/external; P4.23 local query freshness/owner and exact slice GREEN; **future:** P5.5 directory; P8.7 installed external consumer qualification |
| R51 | [ConsistencyTest.php](ConsistencyTest.php) | GREEN cache slice | P4.21 ordinary/scoped-admin expiry=now in warm memo |
| R52 | [ConsistencyTest.php](ConsistencyTest.php), [RevocationRaceTest.php](../../Engines/RevocationRaceTest.php), [ReplicaLagTest.php](../../Engines/ReplicaLagTest.php) | GREEN cache slice | P4.21 local/independent root revoke, request window, Primary/check and real paused-replay Default window |
| R53 | — | future | —; **future:** P6.10 async export actual queue after revoke; P7.2 export consumer |
| R54 | — | future | P4.19/P4.8/P4.20 internal lifecycle/fiber carriers; **future:** P6.2/P6.10 actual Octane/queue lifecycle; P8.7 concurrent external consumer |
| R55 | — | future | P4.19/P4.8/P4.20 internal lifecycle/fiber carriers; **future:** P6.2/P6.10 actual Octane/queue lifecycle; P8.7 concurrent external consumer |
| R56 | — | future | —; **future:** P6.8 generated stubs; P8.7 actual cached consumer boot |
| R57 | [BuildInputsTest.php](BuildInputsTest.php), [BuildStateTest.php](BuildStateTest.php), [RedisStoreTest.php](../../Feature/Authorization/Cache/RedisStoreTest.php) | partial | P4.15/P4.20/P4.21 build/filter capture/cache/incarnation; **future:** P6.6 actual restore; P8.7 stale worker consumer |
| R58 | [ScaleBatchTest.php](ScaleBatchTest.php), [ScaleVisibilityTest.php](ScaleVisibilityTest.php), [VisibilityExplainTest.php](../../Engines/VisibilityExplainTest.php) | GREEN batch/list | P4.9/P4.23: whole scalar/batch/list 10k IDs, 100 scopes/two roles, total/pages and explicit SQL budgets; real PG/MySQL 100k EXPLAIN |
| R59 | — | future | P4.21 root lock/revoke/host transaction slice; **future:** P5.2/P5.3 actual action deletes/new deploy mutation; P8.7 host transfer concurrency |
| R60 | — | future | P4.4/P4.18/P4.21/P4.23 engines relevant SQL/fixtures; **future:** P8.4 framework matrix; P8.7 installed external consumer |
| R61 | [AuthorityModesTest.php](AuthorityModesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R62 | [AuthorityModesTest.php](AuthorityModesTest.php) | GREEN scalar | P4.15 — Authorizer literal controls, source spy/actual target-actor/whole witness |
| R63 | [AuthorityModesTest.php](AuthorityModesTest.php) | partial | P4.1/P4.3/P4.15 PolicyOnly ignore assignment/wildcard read slice; **future:** P5.2/P5.3 exact writes rejected; P6.2/P7.4 HTTP/UI/CLI write boundaries |
| R64 | [VisibilityTest.php](VisibilityTest.php), [AuthorityModesTest.php](AuthorityModesTest.php) | GREEN scalar/exact | P4.23: action mode parity, PolicyOnly without grant tables, Grants outage fails closed |
| R65 | [AuthorityModesTest.php](AuthorityModesTest.php) | partial | P4.17/P4.18/P4.15 enum assignment/dynamic reads; **future:** P5.3 dynamic create opt-in rejection/mode immutability |
| R66 | [AuthorityModesTest.php](AuthorityModesTest.php) | partial | P4.2/P4.15/P4.20 removed keys/build/read mode authority; **future:** P5.3 explicit migration/cleanup; P8.7 stale workers; **future:** P4.20 deployment/cache |
| R67 | [BuildInputsTest.php](BuildInputsTest.php), [ConsumerSpiTest.php](ConsumerSpiTest.php), [RuntimeInputsTest.php](RuntimeInputsTest.php) | partial | P4.3/P4.19/P4.15 local typed DI/definition/model-null/Decides rename-remove; **future:** P8.7 installed consumer/factory whole qualification |
| R68 | BatchTest | partial | P4.9 core: mixed PolicyOnly/DB Grants/relation-only, two panels, typed states/original order/one now passed. Public wrappers/integration consumer remain future P5/P6/P8 |

V109/V111/V113 Access inputs: RuntimeInputsTest и R14 DB/relation; Assignment/Revocation/Inspection write qualification остаётся P5.2/P5.3. V110: NativeFiltersTest. V112: FreshInputsTest. R50: ConsumerSpiTest + ExternalScopeTest, только публичные SPI; installed consumer остаётся P8.7, exact unsupported — P4.23.

Итоговые checks и поломки R11/R13/R14: `plans/2026.10.01-№1-AZGUARD-V1/artifacts/P4.15-execution/`. Нормативное распределение взято из brief/P4-acceptance-matrix (канонический brief.json); будущие кейсы расширяют стенд в owning items.

P4.23 evidence: `artifacts/P4.23-execution/` в активном плане; P14 — 100 seeded scalar/query cases и отдельные denial controls, PG/MySQL — 100 seeded CRM cases в joint authority transaction. Большая exact CRM: 10k клиентов/100 scopes/два role shapes, 8000 разрешённых, 14 SELECT (2 assignment, 4 resource), chunk 500, 1113 final SQL parameters, max SQL 87753 bytes. Materialized scope witnesses остаются ограничены 1000; это не unbounded export. `VisibilityExplainTest` сохраняет реальные SQL/EXPLAIN для 100k строк и проверяет физические `azg_rg_subject` и host `crm_clients_tenant_project`, без planner hints. UI/widget/export/attach остаются P7, installed consumer — P8.7.
