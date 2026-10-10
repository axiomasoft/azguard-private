# Inventory artifacts (architecture revision 2)

Raw evidence behind `knowledge/type-map`, `knowledge/target-structure`, `knowledge/identities`,
`knowledge/upgrade-map` and `knowledge/contracts`. Canonical design lives in the knowledge documents; these files
reproduce them from the code baseline `09612160c98ca6133c894a8f90f5653b9dac6b8f`.

| File | Content |
|:--|:--|
| `scan.py` | scanner of production PHP types (declarations, markers, imports, users) |
| `types-0.7.0.json` | scanner output at the baseline (463 types; `Attributes\CheckPermission` is added by `target_map.py`) |
| `identities-0.7.0.json` | commands, exception codes, doctor keys and event types at the baseline |
| `target_map.py` | placement rules and explicit decisions; produces `target-map.json` |
| `target-map.json` | one row per baseline type plus new types: owner, target FQCN/path, marker, enum class, action, phase, reason |
| `gen_docs.py` | renders the knowledge documents from the two JSON files |
| `contracts-source.md` | source text of `knowledge/contracts` |

Regenerate: `python3 scan.py <repo> types.json && python3 target_map.py types.json target-map.json && python3 gen_docs.py . out`.
P1 boundary scans read `target-map.json` as the ownership oracle.
