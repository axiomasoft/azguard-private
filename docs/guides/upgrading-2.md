# Upgrading to 2.0

The planned core release line is **2.0.0-rc.1 → 2.0.0**. The Filament package is retained for local development and
is not in the current publication scope, and no core split or release is currently authorized. Treat 1.0 as potentially consumed;
the historical changelog is not proof of publication. Review these changes before updating code or stored data.

## Dependencies and package versions

Upgrade Laravel 11 and 12 applications first. Supported pairs are PHP 8.3, 8.4 and 8.5
with Laravel 13. The plugin requires Filament `^5.8.4`. Composer `self.version` binds the plugin to the exact
core version, including the prerelease suffix; update local development identities together. For a core RC evaluation,
select `axiomasoft/azguard:2.0.0-rc.1` explicitly once the RC is published. A development path
repository must assign both packages the same version; the monorepo's `2.0.0` path override is a local solver
identity, not evidence of a stable release. The root development lock does not constrain your application's lock.

## API and extension contracts

Only publication-marked types and their supported members belong to the API/SPI promise. The 2.0 changes include:

| Previous use | Required change |
|---|---|
| `StorageDescriptor::$connection` could be null | It is now a resolved `string` connection name. Use that name; changing Laravel's default does not move existing storage descriptors. |
| `DoctorContext::storages()` relied on its former shape | Iterate `list<StorageDescriptor>`; use descriptor identity and resolved connection, without opening internal storage objects. |
| Direct `DoctorContext::config()` calls | This member is internal. Custom Doctor checks should receive their own application dependencies. |
| Constructing `Visibility`, `PanelOverview`, `GateBridge` or `StorageSchema` directly | Their constructors are internal. Use the documented authorization, diagnostics and storage entry points. |
| `FolderSource::configured()` / `bind()` | These members are internal; configure sources through panel registration and the supported `FolderSource::make()` factory. |
| `FakeSubject::newEloquentBuilder()` | This is an internal Eloquent hook; create a fake subject with `FakeSubject::of()`. |
| Reading `AzGuardPlugin::keys()` | This is internal; use the generator and Doctor as supported views of the keys. |
| Participant table aliases or uppercase names such as `ROLE_GRANTS` | `tables()` must return lowercase ASCII base names matching `[a-z][a-z0-9_]*`. Migrate any renamed tables and update your queries explicitly. |
| Audit retention below one day | Configure at least one day; `AuditOptions` rejects shorter retention. |

`isSuperAdmin()`, `hasRole()`, `roleNames()`, `permissionSet()` and panel admission now use the model from which
the subject wrapper was constructed, consistently with `decide()`. Reload the intended host model before creating
the wrapper; do not expect a separately loaded copy to replace it. Bare `SubjectRef` reads still use the source.
New source metadata does not add a mandatory method to existing source implementations.

### Primary authority and SQL visibility

For sensitive operations on replica-default panels, use `SubjectAccess::onPrimary()` or
`AccessRequest::onPrimary()` and retain `StateRefresh::Check`. Reload host records and membership from primary
too. A primary authority read does not make host data fresh or make check/use atomic; coordinate concurrent
revokes with the host write where required. See [consistency](../advanced/consistency.md).

Unsupported scopes, tenants, roles, conditions or sources still deny or raise the documented unsupported-visibility
error. `visibleTo()` cannot turn unsupported SQL into Allow or authorize by filtering a paginated result.
Policies/restrictions reading matching grants need an equivalent supported predicate for exact visibility.

### Filament enforcement

Attaching, associating, detaching and dissociating, including bulk actions, now require the owner resource's
`update` ability or the relation manager's `$azguardRelationAbility`. Reading alone is insufficient.
Register resource widget keys or provide a deciding trait: an undecided widget mounted in an enforcing panel
is refused with 403. Enforcing panels require `consistency.reads: primary` for writes and exports.
The shared export download route is refused, including legacy exports and other panels; a replacement must
reauthorize the actor, original context and every exported record. Follow the [Filament guide](./filament.md).

## Cache rollover and bounded lifetime

Configure a unique `AZGUARD_CACHE_NAMESPACE` and signing secret (`AZGUARD_CACHE_SIGNING_KEY`, or Laravel's
`APP_KEY` fallback); configure only bounded previous keys during rotation. The current contribution key uses
`azguard:sets:v2:` and includes the signing namespace, source/state identity and read mode. Existing unsigned
entries and entries with a previous key digest are ignored; authority is loaded again. Do not copy or re-sign
old entries. Signed envelope version 1 is distinct from the key prefix and package major version.

Shared permission sets default to 300 seconds, capped at 3600 seconds and the nearest active expiry. A null
shared TTL is a configuration/Doctor error; runtime fallback remains finite. Each request/job memo map is
bounded at 1024 entries; `cache.max_memo_entries` and `cache.max_state_entries` may reduce the limits to 1–1024.
Shared caching requires stable fenced authority and a supported raw store (array or Redis). Redis client
serialization/compression must be disabled. Other stores bypass shared caching and retain request/job memoization.

Restart long-lived workers after deployment and rebuild the catalog cache with the deployment build id.
For old permanent Redis entries, inspect the exact application's connection, prefix, namespace/key generation
and TTL, then remove only the obsolete entries under the [cache cleanup procedure](../advanced/caching.md).
Do not flush a shared database. Reset state after a database restore as the operations runbook requires.

## Machine-format consumers and baseline policy

Regenerate API manifests with `composer api:manifest` and validate with `composer api:check`. Manifest readers
must accept return-by-reference records and `@phpstan-type` contracts. Console snapshot readers must handle option
mode and repeatability. `SourceDescription` and `azguard:panels:list --sources --json` add `volatility` and a
declaration-only `matrix` (`urn:azguard:source-capabilities:2`); a conditional cell does not authorize anything.

Doctor consumers must accept `panels.sources.visibility`, `panels.sources.cache` and `cache.integrity` findings,
the changed `cache.signing` severity and the absence of the store name in `cache.store` details. The production
missing-`catalog.build_id` finding is now an error for a cached catalog or persistent panel cache.
Ignore unknown JSON keys and match open enums with a default arm. See [machine formats](../reference/formats.md).

The previous compatibility candidate is preserved, with its old-to-2.0 breaks and additions in the generated
[migration inventory](../reference/compatibility-2.json). It includes the source metadata PHPDoc changes and the
TTL default change from 3600 to 300. The separately pinned 2.0 candidate starts the same-major comparison;
later 2.x breaks remain blocking. This inventory describes code/format changes, not a qualified database upgrade.
No published migration or historical changelog entry is rewritten by this baseline decision.

## Qualification and rollback

The current [security support policy](../security.md) targets the latest stable 2.x minor once published and
best-effort fixes on `main`; 1.x publication/support remains unverified and no LTS or backport is promised.
This does not establish a qualified historical upgrade or change the publication-baseline gate below.

The 2026-10-30 publication inventory in `RELEASING.md` did not establish a published 1.0 artifact. It does not
prove that 1.0 was never consumed. No published baseline-to-2.0 upgrade has been qualified by this repository's
upgrade drill: `bash bin/upgrade-drill.sh` fails until the required baselines and executable fixtures are available.
A reconstructed schema or a fresh 2.0 installation is not evidence of a published upgrade. On 2026-11-02 both
Packagist stable and development endpoints returned 404 and both split tag queries required unavailable credentials;
neither observation proves absence. For each baseline
established by a current split and Packagist inventory, rehearse on a fresh disposable copy with the matching
baseline artifact and lock file; retain the artifact identity, commit, tool versions and actual logs. Verify seeded
roles, grants, fields, morph identities and audit rows, migrations run twice, matching core and Filament versions,
Doctor, and representative allow and deny decisions. Do not mark unavailable artifacts as passed.

### Published monorepo v0.3.0 inventory

On 2026-11-02, the [GitHub release](https://github.com/axiomasoft/azguard-private/releases/tag/v0.3.0)
was present, non-draft and non-prerelease, published on 2026-07-22, with no attached assets. Origin advertised
annotated tag object `a713e5e6ac47785afa5b15dbdf7d75d15cfb0ea6`, peeling to commit
`d26db856b32761bf73ac16984e9b1a8182a2126c`, matching the local tag. The tagged monorepo contains
`axioma-studio/azguard-core`, `axioma-studio/azguard-filament` and `axioma-studio/azguard-context`;
its root Composer path repository assigns them version `0.3.0`. The core tree is
`178602abb5dff23079266b4c4ec8cf24066dce0f`; the core and context contain their own historical migrations.
This establishes the monorepo release and its contents, not publication of matching split Composer artifacts.

At that tag, `SECURITY.md` described security fixes for the latest 0.x and main. The current
`SECURITY.md` explicitly excludes versions below 1.0, and the compatibility baseline policy in `RELEASING.md`
starts at 1.0. Thus v0.3.0 is not a required supported baseline under the current contract; the historical
security policy alone is not a promise of a 0.3-to-2.0 upgrade. No upgrade fixture was qualified for it and it
does not replace the missing published supported baseline. The V1 gate remains nonzero.

`bash tests/Fixtures/Upgrade/reconstructed-drill.sh` rehearses a schema reconstructed from local candidate
`3d789538` and runs the current core migration twice against retained data. It is not a published 1.0 artifact,
does not install old package code or dependencies, and does not qualify an upgrade. The baseline drill remains
unavailable until artifact-specific executable fixtures and publication evidence are added.

### Historical consumer evidence (not a published baseline)

On 2026-11-02 the local private historical checkout was at commit
`fb89b1b3b7c778f9156ceccb877b4896fff3d1e8`, with core tree
`8c3f9d1963c97d3d9ebf98e8a2a6f726c17b4ffd`. Three local consumer
`composer.lock` files each record `axiomasoft/azguard` as `dev-main` from a path repository, with
reference `95a14066a220caba7ef201fa963fec31d3c29f4c`. These locks are indirect evidence of
consumption; their path references have not been proven to identify that checkout, and neither the locks nor
the checkout establishes a published release.

This historical core schema has no `panel_state.epoch` or `subject_revisions` table, and creates the audit
table as part of core storage. The current schema requires the first two and assigns audit storage to the audit
module. The current initial migration calls `StorageSchema::create`, which creates tables rather than migrating
an existing historical schema. A direct historical database upgrade is therefore not established by the
reconstructed fixture; no historical upgrade or data parity is claimed. A reviewed migration and isolated
rehearsal against a verified consumer database and dependency pair are needed before attempting it. The historical
core also allows Illuminate 11, whereas the current core requires Illuminate 12 or 13; dependency compatibility
must be resolved as part of that rehearsal. This finding does not change the published-baseline gate.

The opt-in test `tests/Fixtures/Upgrade/HistoricalUpgradeTest.php` executes the pinned checkout's actual initial migration and seeds a synthetic role grant
in an in-memory SQLite database, then copies it to a unique temporary SQLite file. With that migration recorded,
the current migrator returns success but leaves schema 1 unchanged; a current storage read rejects the schema.
Forcing the current initial migration instead fails because the tables already exist. The seeded grant remains
present in both cases. This is an incompatibility reproduction, not an upgrade or a consumer lock match; it uses
the current test dependencies, not a historical dependency lock. Run it with an explicitly supplied checkout:
`env -i PATH="$PATH" DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array AZGUARD_HISTORICAL_CHECKOUT=/path/to/checkout vendor/bin/pest tests/Fixtures/Upgrade/HistoricalUpgradeTest.php`.
An absent checkout fails as unavailable. The fixture verifies its commit, core tree and clean core files before executing it.
It then performs a nonrelease SQLite-only experiment on that synthetic database: add epoch and subject revisions,
update its schema marker, retain the seeded role, permission and audit rows, run the current migrator twice,
check an allow and a deny, and reset the panel incarnation and epoch. This is not a production migration or proof
of historical decision parity; it does not validate arbitrary historical data, other database engines, or a consumer lock.

After publication inventory establishes each required baseline, record it in `tests/Fixtures/Upgrade/baselines.php`
with its `id`, an `https` inventory evidence link and the SHA-256 hashes under `sha256.core` and
`sha256.filament`. Review an artifact-specific `baselines/<id>/drill.sh` that installs the matching old artifacts,
seeds and upgrades a fresh SQLite fixture, and actually performs every check below. Put the matching archives at
`$AZGUARD_UPGRADE_ARTIFACT_DIR/<id>/core.zip` and `filament.zip`. Run with `--all-supported --report=<new-file>`;
the sibling `<new-file>.fixtures` directory must not already exist. The dispatcher verifies hashes and invokes
each reviewed fixture with a clean environment, an isolated SQLite path and array cache. Its `result.json` must
name the exact `baseline` and `target` and report true for `baseline_installed`,
`seeded_roles_grants_fields_morph_audit`, `target_installed`, `migrations_twice`, `versions_match`, `doctor`,
`allow_deny_parity` and `state_reset` in `checks`. A missing check, failed fixture or missing artifact fails the gate;
fixture logs remain beside the report. These checkpoints are assertions made by the reviewed fixture, not independent
proof that it performed its work. Do not register the `mock` dispatcher test as a baseline.

There is no promised reverse migration from 2.0 to 1.x. Roll back by restoring the matching old code, dependency
lock and a consistent database backup together. Any writes since that backup require an explicit recovery plan.
Keep traffic stopped until the restore and mandatory state reset in the
[operations runbook](../advanced/operations.md#manual-database-changes-and-restores) are complete.

## Morph identities

Use stable morph aliases for subjects, scopes and tenants. Doctor's `morph.identity` check warns about class-name
identities in production; `azguard_doctor.strict_morph_identity=true` makes that finding an error. Enforced-map
violations, invalid aliases and aliases that collide or do not resolve back to their models are errors.
These diagnostics do not perform a migration and do not make an invalid runtime identity acceptable.

Before changing an Eloquent morph alias or renaming a model whose class name was stored as its type, follow the
[morph identity inventory, migration and rollback runbook](../advanced/operations.md#morph-identity-and-alias-changes).
The application owns the migration and the audit-history policy. Doctor diagnoses the declared models without
enabling Laravel's process-wide morph-map enforcement or changing database rows.
