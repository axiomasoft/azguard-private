---
id: D8
date: 2026-09-22
status: accepted
item: P5
items: [P5, P5.1, P5.2]
supersedes: []
superseded_by: null
---
# D8 — Panel-qualified code roles и безопасный additive scaffold

**Actor:** plan-designer/GPT-6 Codex root (provider selector `gpt-6-sol`; Task route mapping unavailable)
**Evidence:** RAG:— `findings/verification.md` F16–F17; current Role/SyncRoles/HasRoles/ResolvesRole, migration, MakeGuardPanel/stubs, doctor and targeted tests. No external API premise is required.

## Решение

Panel-scoped code role получает persisted `roles.name = {panelId}:{getName()}`. Это глобально
уникальное имя на существующей схеме; `class_name` остаётся точным ключом
code lookup, `roles.id` — identity assignment. Существующая code row с тем же
`class_name` переименовывается in place после preflight, сохраняя ID и pivots.
Collision с другой code или DB-only row, duplicate class row и неоднозначный
FQCN rename fail closed до write. `--dry-run` показывает те же решения; no-op
не повышает P2 revision. Строковый lookup означает точное persisted name;
class/instance lookup не падает обратно на одноимённую DB-only row. Роль без
`class_name` остаётся рабочей; non-null invalid/stale class даёт named runtime
error и doctor error. `level` сохраняется как часть публичного поведения.
Built-in global `SuperAdminRole` сохраняет имя `super-admin` и глобальный `*`
как отдельное зарезервированное исключение без panel ID. `guard:super-admin`
ищет его по точному class, не присваивает DB-only одноимённую row и fail closed
при collision. Unqualified consumer strings
получают явный upgrade path; автоматически угадывать panel для них нельзя.
P6 отдельно решает schema index после migration proof.

`make:guard-panel` создаёт новую панель; новый `make:guard-domain` добавляет
домен в существующую. Оба используют общий проверенный набор stubs, не
перезаписывают чужие файлы, а повторная идентичная генерация — no-op.
Documented safe path принимает существующие model/actor FQCN из options/config;
старый вызов panel без `--model` остаётся совместимым, но предупреждает о
непроверенной legacy convention. Add-domain требует model option/config.
Custom provider/config PHP не редактируется эвристически: точные ручные шаги
вместо разрушительного rewrite. Doctor сохраняет text/JSON shape.

P5.1 имеет `Review=none`; P5.2 выполняет один `light` review итоговых
role/scaffold seams. Plan-wide design audit D4 остаётся отдельным gate.

## Почему

Текущий `roles.name` unique, `class_name` nullable/nonunique. Одинаковые
AdminRole прежде всего сталкиваются с unique name; class fallback по
`getName()` допускает чужую row. Панельный persisted name решает collision без
новой миграции, а in-place rename сохраняет назначения. PHP stub, жёстко
привязанный к `User` и plural domain, не доказывает валидность policy.
Отдельный add-domain command даёт точную область записи.

## Consequences

P5.1 владеет role sync/lookup/diagnostics и документацией несовместимых
string names. P5.2 владеет scaffold, doctor/docs и единственным light review.
Переименование класса без alias/migration остаётся операторским действием;
произвольный пользовательский PHP generator не переписывает.
