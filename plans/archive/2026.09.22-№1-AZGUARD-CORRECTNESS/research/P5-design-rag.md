# Evidence disposition P5 — role identity and scaffold

P5 is deliberately `RAG:—`. No changing external API premise determines the solution.

## Repository evidence

- `roles.name` is globally unique; `class_name` is nullable/nonunique.
- Sync searches code roles by class and creates with raw `getName()`.
- Lookup fallback by name can select a DB-only/foreign row.
- `make:guard-panel` mixes panel creation with first domain, hardcodes model/actor assumptions,
  and cannot safely extend arbitrary provider/config PHP.

These facts are verified in F16–F17 and exact paths named by P5 items. External scaffolding
opinions cannot override the package's compatibility and file-ownership constraints.

