# ООП-пересмотр и проверка реализуемости — 2026-09-30

Область: docs/spec в opus; runtime не реализован. Актуальные контракты:
[19](../19-oop-and-permission-authority.md), [20](../20-process-map.md), [18](../18-contexts-and-runtime-inputs.md).
Historical 01/runtime probes не изменены. Предыдущий [design model](design-model.py) сохранён как архив формулы
policy OR grants; его прежние результаты не доказывают текущие explicit authority modes.

## Исправленные противоречия

| # | Где была проблема / сценарий | Что изменено |
|---|---|---|
| H22 | Роли в БД и mutable role composition против code ownership | PHP-only BaseRole, read-only RoleCatalog; убраны role definition tables/CRUD/events; DB only assignments (05/08/11/12) |
| H23 | using(profile, field/operator params) скрывал реализации и параметры | explicit ContextQueryFilter objects/class FQCN/constructors; no profile registry/JSON (16/18) |
| H24 | RoleView/roleModel/fields facade размывал actual role input | actual BaseRole + explicit target user/actor/grant; role configuration PHP, grant fields separate (05/06/18) |
| H25 | generic BasePlugin.make(array) не совместим с concrete typed named factories | base не определяет factory; каждый plugin свой signature + DTO; bounded PHP contract probe |
| H26 | nested options/models map принимал неизвестные роли моделей | plugin-specific CrmModels, named fields/params, subtype/instantiability/contracts checked; no shared model registry |
| H27 | CrmModels.client был объявлен без конкретного применения | explicit clientScope input + client model resourceScopes binding, model schema/adapter mismatch qualification |
| H28 | Policy true расширял grants, поведение права зависело от источника | explicit PolicyOnly/RequiresGrant; grant mode policy только veto/pass, owned definition unique (02/05/09/19) |
| H29 | Before bool true/superadmin делали неявный authority bypass | typed BeforeResult Continue/Deny; superadmin authority только Grants; attached policy veto/owner сохраняются |
| H30 | Universal StateToken принуждал PolicyOnly читать assignment DB | CodeStateToken vs consumed DB StateToken; dispatcher before runtime source resolve; no irrelevant DB reads (09/20) |
| H31 | UI не отличал definition от assignment; checkbox у policy-only | code role catalogue read-only, grant forms только RequiresGrant, raw rejected, no DB PHP/profile behavior |
| H32 | Filament database/enum/policy одновременно задавал definition source и семантику | FilamentDefinitions Enums/Resources отдельно от explicit authority; safe code generation (11/V75) |
| H33 | Generic update/Change patch позволял выразить identity/scope modification | GrantDetails/PermissionDetails full replacements; withUntil/withFields; schema validation + immutable identity |
| H34 | Class/mode change рассматривался как DB mutation с atomic event | build/deployment protocol, FormerKeys explicit grant migration, stale workers/unknown roles cleanup (19/20) |
| H35 | Разделы/tests сохраняли старую комбинацию динамических ролей/policy fallback | rewritten CRM cases/workstreams/API/schema/events, R01–R68 + V117–V120; archival evidence labelled superseded |

Каждое изменение прослежено от API/definitions до storage/mode dispatcher/query/editor/cache/test requirements.
24 потока F01–F24 имеют owners, вход/выход, failure boundary и real future acceptance cases в 20.
Это устранение противоречий в спецификации, не доказанная безошибочность implementation.

## Что спросили у Perplexity

Выполнено три дополнительных запроса в этом проходе:

1. Узкий PHP/OOP разбор: code roles и DB assignments, typed query filters/plugin DTO, inherited factory LSP,
   native DI/lifecycles, stale role keys/FormerKeys, unsupported query shapes.
2. Полное описание структуры и процессов: pure Kernel/Laravel adapter; panel tree и builder; native guard adapter;
   PHP-only roles; ProjectContext/common+role query composition; two authority modes; optional actions; plugin
   named factories/config/runtime; full grants/state schema; state lock/pipes/root commit; host TOCTOU; SQL exact;
   code vs DB state; UI/worker/import/cache; future real CRM cases. Запрошены contradictions/feasibility walkthrough,
   конкретные OSS sources и minimal corrections вместо нового generic engine/DSL.
3. Точечное OSS уточнение: actual Filament Plugin interface, Spatie PermissionRegistrar Gate callback,
   Bouncer scope/clipboard/cache, Symfony voting strategy vs обязательное grant AND policy.

Длинный ответ содержал пустые обещанные разделы «14 issues»/«Open-source comparison»: их не приняли за evidence
или готовое ревью. Направленный follow-up дал конкретные leads; источники после этого открыты напрямую.
Perplexity conclusions — hypotheses; scheme naming/formulas остаются решениями проекта.

## Первичные источники и применимость

| Источник, открытый напрямую | Подтверждённое наблюдение | Применение / ограничение |
|---|---|---|
| [PHP variance](https://www.php.net/manual/en/language.oop5.variance.php), [named arguments](https://www.php.net/manual/en/functions.arguments.php) | Child input signatures должны сохранять substitutability; named parameter names часть вызова | generic inherited factory не навязывается конкретным typed factory; class-string проверяется runtime |
| [Filament 5 Plugin.php](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/src/Contracts/Plugin.php), [5.x plugins](https://filamentphp.com/docs/5.x/plugins/panel-plugins) | Interface содержит getId/register/boot, factory make не входит в interface; panel configuration/local options | reusable lifecycle pattern. Filament boot runs on panel use, наш freeze/boot build contract отдельный и не назван точной копией |
| [Spatie PermissionRegistrar.php](https://raw.githubusercontent.com/spatie/laravel-permission/main/src/PermissionRegistrar.php) | registerPermissions добавляет Gate.before; успешный checkPermissionTo даёт true, failure null; cache clear отдельно | registrar/cache pattern полезен; early allow shortcut нельзя перенести на RequiresGrant+mandatory business policy |
| [Bouncer.php](https://raw.githubusercontent.com/JosephSilber/bouncer/master/src/Bouncer.php) | scope через Models.scope; cached/noncached clipboard и refresh/refreshFor; global model setters | идеи scope/revision boundaries полезны; mutable global model/scope registry не принимается для parallel panels |
| [Laravel 13 authorization](https://laravel.com/docs/13.x/authorization) | Gate.before non-null решает проверку, after не меняет non-null result; inline checks пропускают hooks | package BeforeResult semantics отдельно, authoritative direct API для protected actions; host bridge qualification |
| [Symfony voters](https://symfony.com/doc/current/security/voters.html) | Voters/aggregation отдельный механизм | прочитан как lead; детали стратегий из ответа без точной primary проверки не приняты за контракт; новый voter engine не добавлен |

Разные open-source библиотеки не подтверждают все semantics нашей системы. Приняты отдельные механизмы, а не
их DB-defined role persistence или общая стратегия affirmative OR. Движущиеся branches main/master просмотрены
на дату ревью и не являются pinned compatibility гарантиями для будущего пакета.

## Проверка и её границы

[oop-contracts.php](oop-contracts.php) — bounded PHP language contract probe: concrete factories/DTO/named args,
base без make, clone helper, model/type/mismatch rejection и explicit model/role + native container service DI.
Это адаптированные минимальные skeleton classes, не новый AzGuard и не тесты authorizer/query/storage/UI.
[validate-dossier.py](validate-dossier.py) проверяет ссылки/нумерацию и запрещённые устаревшие API sketches;
`git diff --check` — whitespace. Запуск 2026-09-30: PHP probe прошёл; dossier validator проверил 409 local links/anchors,
D/V/R/F/C наборы, owning items и D71–D83 API sketches — ошибок нет. `git diff --check` прошёл.
Это квалификация спецификации и ограниченных language contracts; skip не считается pass.
R01–R68/V117–V120 — **future**, real readiness не объявлена. Полный пакетный suite для docs не запускался.
