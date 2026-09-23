# Дизайн P5 — role identity and safe additive scaffold

**Статус:** нормативный dossier для P5.1–P5.2.

## 0. Инварианты

1. `roles.id` is assignment identity; code role class and persisted name are lookup dimensions.
2. Panel-scoped code roles with equal human name must remain distinct.
3. Existing row rename preserves ID and all pivots or fails before write.
4. DB-only role (`class_name=null`) remains supported and is never silently adopted by a class.
5. Invalid/stale non-null class fails closed with actionable diagnostics.
6. Panel creation and domain addition are different write scopes.
7. Generator never regex-rewrites arbitrary consumer PHP or overwrites unrelated files.

## 1. Code-role identity (P5.1)

Canonical persisted name for a panel-scoped definition is:

```text
{validated panel id}:{RoleInterface::getName()}
```

`class_name` is the exact code lookup key; `roles.id` remains referenced by assignments. A sync
preflight loads definitions and current rows, then classifies each as create, no-op, controlled
rename, collision or invalid. It detects duplicate definitions/rows, canonical-name collision
with code or DB-only rows, FQCN ambiguity and length/syntax failures before any mutation.

Controlled legacy rename updates one existing class row in place inside P2 transaction/revision
protocol. Dry-run executes the same classification and reports old/new names without writes.
No-op preserves revision. Class/instance lookup compares exact `class_name`; string lookup compares
exact persisted `name`. Neither falls back from a missing class to `getName()`.

`class_name=null` means DB-only. Non-null but missing/noncontract class is invalid state: runtime
throws named error and doctor reports an error. `level` and configured Role subclass semantics
remain intact. P6 later adds DB uniqueness after cleanup proof.

Global `SuperAdminRole` is the explicit exception: reserved name `super-admin`, exact class lookup,
global `*`. A DB-only same-name row is not silently reused; collision fails before assignment.

## 2. Consumer compatibility

Old unqualified code-role strings cannot be guessed across panels. Upgrade docs require class
strings/instances or new qualified names. Existing code row with exact class follows controlled
rename; arbitrary DB-only rows are not migrated. Class rename itself needs alias/manual mapping;
P5 does not infer historical FQCNs.

## 3. Scaffold write model (P5.2)

`make:guard-panel` owns creation of a new panel shell. `make:guard-domain` owns adding one domain
to an existing package-generated panel. Both share validation, rendering, conflict detection and
atomic target writes, but not a broad generator framework.

Preflight validates:

- panel/domain as PHP identifiers and path containment;
- existing Eloquent domain model FQCN;
- actor FQCN from explicit option or configured auth provider and `Authenticatable` contract;
- complete target/conflict set before filesystem mutation;
- whether provider/config file matches the package's own generated template.

New panel fails when its path is occupied. Add-domain fails for unknown panel and touches only new
domain targets plus recognized generated enum/provider registration. Identical rerun is byte-no-op.
Conflicting content reports exact files/remedy. `--force` is limited to owned targets and never the
whole panel. Unsupported custom PHP remains unchanged; command prints exact manual registration.

Legacy panel invocation without `--model` remains accepted with an explicit unvalidated-convention
warning. Add-domain requires a real model/config mapping. Policy substitutes validated model and
actor FQCNs, never pluralized domain text.

## 4. Worked examples

- `Admin` panel has `Documents` with `Document` model and custom `BackofficeUser` actor; generated
  policy imports those exact classes and passes `php -l`/class-load smoke.
- Adding `Invoices` appends only package-generated enum/provider entries; custom method below the
  generated block remains byte-identical. Second identical run changes no bytes.
- Two panels define `AdminRole::getName() = admin`; persisted names `sales:admin` and `support:admin`
  have stable distinct row IDs.
- Existing DB-only `sales:admin` blocks code-role creation and yields manual resolution, not merge.

Detailed examples: `../artifacts/P5-design/examples.md`.

## 5. Failure modes

- Name fallback for class lookup; mass assigning `class_name`; renaming by delete/create.
- Adding unique DB index before duplicate/collision cleanup.
- Regex-editing arbitrary PHP; treating existing directory as permission to overwrite.
- Deriving model FQCN from plural domain; hardcoding `App\\Models\\User`.
- `--force` on panel tree; partial provider/config update after target failure.

## 6. Validation and mapping

P5.1 covers two equal names, controlled rename/pivots, retry/no-op, exact lookup, DB-only and invalid
class, super-admin, custom Role subclass, P2 revision. P5.2 covers new/add/repeat/conflict/force,
invalid identifiers/path/model/actor, custom PHP fallback, generated PHP smoke, doctor text/JSON.

| Item | Owns |
|:--|:--|
| P5.1 | canonical identity, sync/lookup/super-admin/diagnostics/docs |
| P5.2 | panel/domain commands, stubs/registration/filesystem safety and final review |

