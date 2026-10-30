# Custom source capabilities

`Source` still requires only `id()`. Keep implementing the existing capability interfaces; there is no mandatory
`capabilities()` method. A minimal source with only an id is a valid, inert panel participant. See the
[source guide](../advanced/sources.md) for registration and the `SourceContractTests` trait for consumer contract tests.

The [machine-readable matrix](../reference/source-capabilities.json) covers built-ins and representative custom
sources. Its schema is `urn:azguard:source-capabilities:2`. Each source has six cells: `scalar`, `batch`,
`exact_visibility`, `stable_cache`, `tenant_inheritance` and `filament`. Each cell has a `status` of `supported`,
`unsupported` or `conditional`, a `basis` of `static_declaration`, a reason and a repository test reference.

Read the actual panel declarations with `Panel::sources()` or `azguard:panels:list --sources --json`.
`SourceDescription::$matrix` describes source declarations; `volatility` comes from the attached grant source.
It does not authorize a subject or certify the other sources, hooks or policies of the panel.

## Existing interfaces and their limits

| Declaration | Meaning and limits |
|---|---|
| `ProvidesGrants` / `ProvidesRoleGrants` | Supplies direct grants / role contributions to scalar and batch checks. Batch uses the scalar pipeline and does not promise one bulk read. |
| `Volatility::Volatile` | Re-read on every check. No permission-set reuse. |
| `Volatility::Request` | Reuse within the request or job. No cross-request reuse. |
| `Volatility::Stable` + `FencesReads` | Eligible for cross-request reuse when a cache store is configured. The state must change whenever authority changes. Stable without a fence remains valid but only reuses within a request. |
| `FiltersQueries` | Supplies assignment candidates and raw witnesses through `AssignmentScopeSelection`. A selection is never an Allow; null means runtime unsupported. |
| `ProvidesPermissions`, `ProvidesRoles`, `ProvidesPolicies` | Contributes catalog definitions and bindings. A policy declaration does not certify SQL equivalence. |
| `DescribesSchema` | Optional labels and grant fields. Old constructor calls still work; the panel enriches descriptions with the attached source's volatility. |

Tenant inheritance still depends on panel tenant policy, membership and scope qualifiers. Filament requires its
plugin and panel setup; editing grants requires the database writer. Record lists also require exact visibility.

## Doctor before serving requests

Run `php artisan azguard:doctor --json` against the configured panels before serving requests. The existing
`panels.sources` check emits these additional stable warning ids:

| Finding | `details.reason` | What is known before a request |
|---|---|---|
| `panels.sources.visibility` | `missing_query_support` | A grant source lacks `FiltersQueries`; exact visibility cannot use it. |
| `panels.sources.visibility` | `automatic_roles` | A registered `GrantedAutomatically` role makes the folder source return null for exact selection. This remains true even if that role implements a predicate adapter. |
| `panels.sources.cache` | `stable_unfenced` | Stable grants lack `FencesReads`; cross-request permission-set caching is unavailable. |

These are warnings because legacy sources remain valid for scalar and batch use. Doctor reads declarations and the
compiled role catalog; it does not call grant readers, automatic role rules or arbitrary policies to prove a request.

## Conditional visibility is checked at runtime

`FiltersQueries` is necessary for grant sources, but its presence does not prove that every call will work.
Selections must return valid witnesses, and policies, before hooks, conditions and scopes must provide compatible
`FiltersAccessQueries` predicates wherever exact query equivalence is required. Arbitrary closures, unsupported
predicates, row-dependent matching grants and budgets can still produce `VisibilityNotSupportedException`.
Doctor cannot statically prove arbitrary PHP behavior. Unsupported SQL never means Allow, and applications must
not replace it with PHP filtering after pagination.

## Upgrade and format baseline

Existing source implementations and old `SourceDescription` constructor calls remain valid. The optional trailing
`volatility` constructor argument and readonly `matrix` property are additive in 2.0. JSON consumers must accept the
new `volatility` and `matrix` fields on panel source rows and distinguish declaration status from runtime failures.
Unknown volatility stays null; fenced sources without a known volatility remain conditional for stable caching.

The matrix and its exact cells are registered in `compatibility.json`. Regenerate the matrix and its compatibility
fixture with `AZGUARD_FORMATS_WRITE=1 vendor/bin/pest tests/Feature/Diagnostics/SourceCapabilitiesTest.php`.
Regenerate the doctor inventory with the focused writer run of `tests/Regression/Formats/DoctorSnapshotTest.php`,
and API manifests with `composer api:manifest`. The release coordinator owns replacing the compatibility baseline;
these fixtures describe the intended 2.0 surface, not a new stable release claim.
