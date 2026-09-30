> **Пересмотр D80–D83:** текущие roles/filter/plugin/authority контракты находятся в [19](../19-oop-and-permission-authority.md)
> и [20](../20-process-map.md). Предыдущие findings/research ниже — история проработки. Строковые profiles, DB роли
> и policy OR grants больше не входят в target. Старый reference model не является proof новой формулы.

# Дополнительный разбор гибкости — 2026-09-30

Область: целевая спецификация; runtime пакета не изменялся. Новые документы:
[контексты и входы](../18-contexts-and-runtime-inputs.md), [реальная CRM-приёмка](../17-crm-acceptance-tests.md).
Это продолжение [design review](design-review.md), а не отчёт выполненных acceptance tests.

## Разбор проблем

| # | Проблема | Принятое решение / проверка |
|---|---|---|
| H15 | Context role class list не передаёт настройки, user/role inputs | immutable recipes глобально/на binding; BaseContext/query/profiles, explicit ContextRuntime/RoleView (D75/D77) |
| H16 | Seller city становится общим запретом или смешивается с чужой grant | global filters AND, внутри одной role contribution AND, между contributions OR; одна eligibility формула scalar/list/directory (D76) |
| H17 | Callback/query/global scope снимает owner boundary; scalar/list различаются | native predicate Builder, protected outer correlation/grouping; unsupported adapter явен; настоящий set-based SQL и expected ids R18/R31–R40 |
| H18 | Inactive/expired/deleted context невозможно отозвать через общий query | Access/Assignment/Revocation/Inspection, actor authority отдельно, stored scope cleanup (D77/R28) |
| H19 | Plugin singleton/options/cache удерживает чужую панель/user | fresh makeWith(options), immutable build input, runtime capabilities fresh per operation, scoped DI и recipes/build id (D78/R41–R57) |
| H20 | guard(string) конфликтует с уже существующим Eloquent guard(array) | native-compatible array|string adapter + wrapper selector, native named args/fill; executable signature probe, real matrix V108/R03 |
| H21 | Много unit tests создают необоснованное заключение о production readiness | 60 будущих реальных CRM cases, positive controls, actual SQL/UI/workers/races/qualification report (D79/P8.7) |

## Perplexity и первичные источники

Два дополнительных запроса описывали фактическую задачу без предположения, что Perplexity знает AzGuard:

1. Laravel package: панели, org tenant, ProjectContext descriptor, global active predicate, role-specific city/
   region, static/dynamic roles, user и actor отдельно, exact scalar/query, assignment и inactive revoke.
2. Plugin config/model/context/service inputs, fresh factories, register/boot и runtime capabilities,
   Laravel DI/scoped lifecycles, Octane, closure catalog cache и multi-panel isolation.

Синтез использован как список гипотез. Native query grouping/DI/lifecycle проверены в первичных источниках:
[Laravel 13 Container: method injection/scoped bindings](https://laravel.com/docs/13.x/container),
[Eloquent: removing global scopes](https://laravel.com/docs/13.x/eloquent),
[Query Builder: logical grouping](https://laravel.com/docs/13.x/queries#logical-grouping),
[Octane: dependency injection/container/request lifecycle](https://laravel.com/docs/13.x/octane).
Ни один источник не предписывает наш fluent naming или предметную authorization формулу; это решения спецификации.

Для guard прочитан непосредственно установленный framework:
`Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php`, guard(array $guarded), native fill/getGuarded;
`composer.lock`: Laravel v13.33.0, source 91188a17ceaa3dbace6e8a5f7abd0d042e466359.
Container resolve проверен локально: nonempty parameters обходят обычный shared instance cache; custom factory
всё ещё может вернуть shared object, поэтому makeWith сам по себе не доказывает изоляцию произвольного binding.
Laravel scoped не объявлен достаточной изоляцией одновременно работающих fibers.

## Что фактически выполнено

- [guard-signature.php](guard-signature.php): самостоятельный probe PHP inheritance/union signature на установленном
  Eloquent; array/native named argument/mergeGuarded/string wrappers/guarded-state/fill проверены без БД.
  Первый draft assertion ошибочно ожидал exception от native fill: Eloquent может молча отбросить guarded field.
  Assertion исправлен на отсутствие поля в attributes; после исправления probe прошёл. Это ошибка draft probe,
  не исправленный дефект runtime пакета. Выполнение: `php audits/2026-09-29-audit/opus/evidence/guard-signature.php`.
- [validate-dossier.py](validate-dossier.py): local links/anchors, D01–D79, V01–V116 (V40–V42 retired),
  C01–C22, R01–R60; owning references/duplicates, layout, naming и API signatures.
- Dossier validator: **385 local links**, все разрешены; D/V/C/R numbering без пропусков/дублей, owning
  references/layout/rename/API checks прошли. `git diff --check` прошёл.

Probe не исполняет новый HasAzGuard и не подтверждает всю PHP/Laravel matrix. Markdown validation не подтверждает
runtime query grouping/DI/plugin isolation. R01–R60 остаются **future** до реализации и реального выполнения.
Полный пакетный suite для этой документационной работы не запускался; исторические baseline/probes не пересчитаны.
