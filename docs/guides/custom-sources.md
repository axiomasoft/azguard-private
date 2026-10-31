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

## Testing a mutable fence

Existing `SourceContractTests` consumers need no new methods. For a mutable `FencesReads` source, override the
optional `azguardFenceMutation(Source $source, Panel $panel): ?callable` hook. Return a callable accepting `grant`
or `revoke` that changes the suite subject's global-scope authority on the attached source. Both operations must be
idempotent, and the grant must be visible to the source. The contract checks that real changes advance the token,
repeated operations leave it unchanged, and identical state yields identical raw contributions. Without a fixture
the mutation branch is not run; a passing base suite alone does not prove a mutable fence.

For exact query coverage, override `azguardQueryParity(): ?array` with a `supported` boolean and `scalar`, `batch`, and `visibility`
callbacks. Each returns the same non-empty resource-id to boolean map, including denied resources. The visibility
callback may throw `VisibilityNotSupportedException` for an unsupported runtime. Use a fixture containing both
allowed and denied resources; the contract compares results and catches incomplete selections, but cannot infer
whether a chosen universe exercises all of an application's scopes or adapters. Without this hook the parity branch
is not run. Supply an independent `expected` resource-id to boolean map to catch cases where scalar, batch and
visibility agree on the same wrong answer, including tenant and context leaks. All results must be actual booleans.

Membership and other host data used by a Stable source must also be covered by its token. Otherwise declare the
source Volatile and test that changes are seen without caching. A source author should exercise tenant and context
boundaries, expiry, batch and exact-visibility parity using representative grants and the application's adapters;
the generic mutation fixture cannot infer those cases.

`RelationSource` is Volatile: it reads host membership, which may change without an AzGuard state update, on every
check. Applications upgrading from request-level reuse should account for the additional membership reads.

## Doctor before serving requests

Run `php artisan azguard:doctor --json` against the configured panels before serving requests. The existing
`panels.sources` check emits these additional stable warning ids:

| Finding | `details.reason` | What is known before a request |
|---|---|---|
| `panels.sources.visibility` | `missing_query_support` | A grant source lacks `FiltersQueries`; exact visibility cannot use it. |
| `panels.sources.visibility` | `automatic_roles` | A registered `GrantedAutomatically` role lacks `SelectsAutomaticRoleScopes`; a predicate adapter alone does not provide exact selection. |
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

Automatic roles may additionally implement `SelectsAutomaticRoleScopes`. For a subject, tenant and mapping type,
the method returns the complete finite list of `AccessScope` values for which the scalar `appliesTo()` would grant
the role across the requested resource universe. It must include applicable tenant-wide and allowed global-tenant
scopes. Return `null` for an unknown, incomplete, unbounded or otherwise inexact result; an empty list asserts no
matches. The framework cannot infer completeness from a list, so this is an obligation of the implementation.
`null` mapping type means no resource mapping, whereas the string `global` is a concrete scope type. FolderSource
constructs the role witnesses and validates their scope and tenant; more than 1000 reported scopes is unsupported.
Selections remain separate from granted-to-all and other sources. A throwing adapter, malformed scope, unsupported
sibling, or unsupported condition fails the whole exact query. Legacy roles need no changes for scalar authorization;
without this optional SPI their exact visibility remains unsupported. Existing `FiltersQueries` signatures are unchanged.

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
