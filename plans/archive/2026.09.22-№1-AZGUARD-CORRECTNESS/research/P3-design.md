# Дизайн P3 — единый subclass model contract

**Статус:** нормативный dossier для P3.1–P3.2.

## 0. Инварианты

1. Config key содержит existing subclass документированной AzGuard base model.
2. Официальный model-backed path начинается из configured class/instance.
3. Concrete base type-hint не является bypass: subclass совместим с ним.
4. Table, connection, events, casts и scopes принадлежат configured model.
5. Все четыре models, pivots и P2 revision работают на одной effective connection.
6. Raw pivot JOIN не получает выдуманные model scopes.
7. Filament и core используют один Config validator; диагностика не дублирует правила.

## 1. Gap map

| Seam | Текущий риск | Целевой источник модели |
|:--|:--|:--|
| `HasRoles` sync/relations | static `Role` | `Config::roleModel()` |
| `HasScopedRoles` | static pivot/model paths | configured Scope/Role plus table config |
| `HasDirectGrants` / `GrantBuilder` | default DirectGrant | `Config::directGrantModel()` |
| `DatabaseRoleGrantSource` | `DB::table()` start | configured RolePermission Eloquent builder |
| Filament Role/DirectGrant resources | fixed `$model` and default queries | Resource `getModel()` -> Config |
| doctor | partial/no cross-model validation | raw value + base/table/connection verdict |

F9–F10 are a targeted map, not a mandate to remove every base-class import.

## 2. Config accessors and error contract

Each accessor resolves raw config once per call/bound lifecycle, checks non-empty class string,
class existence and exact base subclass, then returns `class-string<BaseModel>`. One named
exception includes config key, received value and expected base. Invalid config must be reportable
by doctor without causing an unhandled fatal.

Effective connection is obtained from instantiated configured model after normal Laravel
resolution. Before a multi-model mutation/query, compare connection names for Role, Scope,
DirectGrant, RolePermission, relevant pivots and P2 permission-state. A mismatch fails before
any row/query side effect; P3 does not build distributed joins/transactions.

## 3. Core query/write rules (P3.1)

Relations and writes use configured class or an owner relation. Default-class static calls on an
official path are replaced. Correct return/parameter type declarations remain base types where
they accept all subclasses.

`DatabaseRoleGrantSource` starts from configured RolePermission model builder. It therefore uses
that model's table, connection and global scopes. It joins the configured pivot table on the same
connection. A raw pivot is not a model: Role/other scopes are not silently projected onto it.

Custom subclass matrix must observe:

- distinct configured tables;
- create/update/delete events on the subclass;
- DirectGrant and RolePermission global scopes on their originating reads;
- relationship/attach/sync outputs typed as subclass-compatible models;
- no default model instantiation in selected official paths.

## 4. Filament and operator rules (P3.2)

RoleResource and DirectGrantResource override `getModel()` through core Config. Pages, filters,
relation managers and actions call `static::getModel()`, owner relations, or P2 synchronizer;
they do not reintroduce the default class. `getEloquentQuery()` may extend parent builder but
must not switch to `DB::table()`.

Doctor text and JSON report, for each key: raw class, expected base, resolved table and effective
connection. Invalid class/base is one actionable diagnostic; split authorization connections are
an error matching runtime fail-fast. Output shape remains compatible.

## 5. Worked examples

- `CustomRole extends Role` uses `custom_roles`, connection `authz`, event marker. Role relation,
  CLI sync and Filament create must all hit `custom_roles` and fire marker.
- `CustomRolePermission` has global scope `tenant_id=7`; database-role source must honor it because
  builder originates from this model. It must not invent that scope on the joined pivot.
- Three models use `authz`, DirectGrant uses `default`: doctor and runtime reject before mutation.
- Config points to `stdClass`: named config exception states expected `DirectGrant` subclass.

Examples: `../artifacts/P3-design/examples.md`.

## 6. Forbidden shortcuts

- New ModelRegistry/repository layer; table/connection cache as replacement for model semantics.
- Blanket ban on base-class type-hints.
- `DB::connection(...)->table(...)` presented as preserving subclass behavior.
- Duplicated validation inside each Filament resource.
- Accepting split connections because a read happened to work on SQLite.

## 7. Validation matrix

P3.1 tests four subclass keys across table/event/scope/relation/write/source and invalid/mismatch
cases; defaults remain green. P3.2 tests Resource model/list/filter/create/edit/delete, P2 sync and
doctor text/JSON. One final light review covers only core/Filament/diagnostics seams.

## 8. Item mapping

| Item | Owns |
|:--|:--|
| P3.1 | Config validation, core relations/builders/source, connection guard |
| P3.2 | Filament pages/resources/actions, diagnostics, docs and seam review |

