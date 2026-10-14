# Roadmap

These items are not part of AzGuard 1.0. Each one says why it is deferred and what would bring it back. None of
them is a promise or a date. Open an issue with a concrete use case if one of them blocks you.

## Consistency

| Item | Why it is deferred | When to revisit |
|:--|:--|:--|
| Freshness receipt for replica reads | `Reads::Primary` is the default and gives strict freshness. With `Reads::Default` a replica can lag, which is documented and reported by `azguard:doctor` (`consistency.reads`). The receipt mostly exists already: `ChangeResult->state` carries the state token of a write. What is missing is an API that makes a replica read wait until it has caught up to a given token. | Applications that must read authorization data from replicas and still need read-your-writes after a grant or revoke. |
| Subject-level write locking | Every write locks the `panel_state` row of its panel, so writes to one panel are serialized. Reads take no locks and are not blocked. Locking per subject would let writes for different subjects run in parallel. It needs a new lock order, deadlock analysis across drivers and changes to the panel epoch. | Measured write contention on one panel, for example bulk grant imports competing with interactive edits. The bench `consistency-load` profile reports lock wait and hold times. |

## Panels and identities

| Item | Why it is deferred | When to revisit |
|:--|:--|:--|
| Immutable compilation of the panel DSL | Panels are built by a fluent DSL and validated when the application boots. Compiling them into an immutable object would rule out runtime mutation by design, but it means a new public DSL and has no correctness bug behind it today. | A measured case of runtime panel mutation or catalog inconsistency. |
| Mandatory morph map | AzGuard already refuses identities without a type alias, and `azguard:doctor` reports panel models without a morph alias. Requiring the application to enforce its morph map (`Relation::requireMorphMap()`) or to allowlist model namespaces would also cover polymorphic columns outside AzGuard identities, but it changes what existing applications must configure. | A threat model that needs it and an upgrade path for existing applications. |

## Audit

| Item | Why it is deferred | When to revisit |
|:--|:--|:--|
| Transactional outbox for an external audit trail | `AuditPlugin` writes one row per effective change in the same transaction as the change, and change events fire after commit. There is no consumer yet that needs guaranteed delivery to an external system. | A requirement for guaranteed, at-least-once delivery of changes to an external audit store or message bus. |

## Release

| Item | Why it is deferred | When to revisit |
|:--|:--|:--|
| SBOM and signed releases | Releases are tagged from CI and split into the package repositories. No consumer has asked for an SBOM, artifact signing or build provenance yet, and the release process has no baseline for that gate. | A consumer or compliance requirement for SBOMs or verifiable release artifacts. |

## Filament

| Item | Why it is deferred | When to revisit |
|:--|:--|:--|
| Filter grant tables by grant fields | A field declared with `inMeta()` lives in the JSON `meta` column, which is not indexed. A filter on it would scan the whole panel and tenant partition, and the `GrantManager::page()` cursor would have to depend on a condition over untyped JSON. A column of your own grant model is indexable, but filtering on it needs a new public `GrantFilter` condition and operators per field type. | An administrator scenario with enough grants that the role, subject, context, expiry and granted-by filters are not enough, plus a decision on which fields can be filtered (most likely only columns of your own grant model). |
