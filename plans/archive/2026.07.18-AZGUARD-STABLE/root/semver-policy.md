# AzGuard SemVer policy (0.x) — produced by P3.3

> **Status:** SSOT for how AzGuard versions its public API pre-1.0. Consumed by `docs/introduction/upgrading.md`
> (breaking-change catalog) and `root/known-limitations.md` (documented, non-blocking gaps). At archival
> (`/task:plan-close archive`, D26) this file's content moves to `docs/introduction/versioning.md` (EN+RU).

## 1. What counts as the public API

The public API = the `@api`-tagged surface **frozen by the snapshot gate** (`tests/Unit/ApiBoundaryTest.php`,
P3.2, fixture `tests/Fixtures/api-surface.snapshot.php`): every `@api`-tagged class/interface/enum/trait under
`packages/core/src` and its publicly-declared methods (name, static-ness, parameter order **and names**, types,
by-ref/variadic, default-presence, return type). The human-readable registry is `root/api-surface.md` (P3.1).

`@internal`-tagged types and methods are **not** covered by SemVer guarantees, even if they are `public` PHP
visibility — `@internal` marks an orchestration seam (fluent/trait entry-point calls it), not a consumer contract.
Two consequences worth naming explicitly (both exercised in this cycle, P3.1/D29):

- De-publication (`@api` → `@internal`) is **not** a removal. The method keeps working; only the documented
  contract shrinks. It is still a **breaking** change for anyone who called it directly (below), but it does not
  require deleting the method — see facade cut-line (`root/contracts/facade-cutline.md`).
- Snapshot scope is **core-only**, matching `ApiBoundaryTest`'s existing boundary (`root/api-surface.md` §8).
  `filament`/`context` are documented but do not (yet) run the `@api`/`@internal` reflection convention — see
  `root/known-limitations.md` §1.

## 2. What is breaking before 1.0

Per [semver.org](https://semver.org), a `0.y.z` release may break the API in a `MINOR` bump — there is no
`PATCH`-only breaking-change guarantee below 1.0. AzGuard's `0.x` releases (this one: `0.2.0 → 0.3.0`) use that
freedom, but define breaking narrowly and precisely so consumers can grep their way through an upgrade:

A change to an `@api`-tagged element is **breaking** if it does any of:

1. **Removes** the element (class/interface/method/enum case), or de-publishes it (`@api` → `@internal`).
2. **Renames** the element (class/method/config key/migration table/column) with no compatibility alias.
3. **Changes a method signature** — parameter order, parameter **name** (this project writes call sites with
   named arguments — `php:named-arguments` — so a parameter rename is a call-site break, not cosmetic),
   parameter type (narrowing), or return type.
4. **Changes runtime default behavior** for an existing config key or feature flag (e.g. a matcher default
   flip) — even though the method/key itself is untouched, the *meaning* of "no config" changes.
5. **Adds a required interface method** external re-implementers must now provide (interface-addition breaks
   the substitution contract even without touching existing methods) — none in 0.3.0; noted here because
   `ARCHITECT_REVIEW.md` §5 flags this specific case for 1.0 (`PermissionCatalog::flush()`).

A change is **not** breaking if it is purely additive (new optional parameter with a default, new fluent method,
new config key with a value that reproduces prior behavior, new `@api` type) or if it only touches `@internal`
surface.

## 3. Deprecate-first discipline

Below 1.0 the project takes the freedom SemVer grants, but **does not exercise it silently**. Every breaking
change in this cycle was decided by an explicit Decision Log entry (`plan.md` §5, `D#`) before it shipped — that
discipline continues past this plan for any future `@api` change. Where removing something outright would cost a
consumer more than a warning, the project prefers a **one-cycle deprecation window** instead of an instant
removal:

- **Legacy wildcard grammar** (`features.wildcard_permission = true`, D18): restores the pre-0.3 dot-crossing
  matcher for exactly one deprecate cycle; `WildcardPermissionMatcher` and the flag are removed together in the
  cycle *after* 0.3.0 (tracked in `root/known-limitations.md` §3).
- Where no compatibility alias exists (this cycle: grant-verb renames, namespace moves, `panel_check` argument
  order, `AzGuardManagerInterface` de-publications), the change is announced **in `upgrading.md` with a
  grep-verifiable migration command** instead of a soft alias — the project's pre-1.0 convention is *rename
  cleanly + document precisely*, not carry parallel names indefinitely (see `docs/introduction/upgrading.md`
  "Pre-1.0 cleanup" precedent — no compatibility aliases policy already established there).

Going forward (post-0.3.0, still pre-1.0), a breaking `@api` change should:

1. Get its own `D#` decision (or plan-item equivalent) naming *why* the break is worth it now vs. deferring.
2. Land in the same release as the `upgrading.md` entry (old → new + grep command) — never a "we'll document it
   later" gap (this is exactly the omission Audit P2 F2 caught and this item exists to close).
3. Regenerate the snapshot fixture in the **same commit** (§4) — a red gate that isn't re-greened same-commit
   means the freeze and the code have silently diverged.

## 4. The snapshot gate as machine enforcer

`tests/Unit/ApiBoundaryTest.php` (P3.2) is the **mechanical** enforcer of everything in §1–§2: it does not know
*why* a change happened, only *that* the `@api` surface moved. It is deliberately over-sensitive (D20) — it reds
on any drift, including additive/BC-safe ones — because pre-1.0 "is this actually breaking?" is a human judgment
call this gate refuses to make for you.

**Legal procedure to change the frozen surface (post-P3):**

1. Make the code change.
2. Run the full suite; `ApiBoundaryTest` reds with a readable added/removed/lost/gained diff.
3. Confirm the diff matches intent (nothing moved that shouldn't have).
4. Regenerate the fixture deliberately: `AZ_UPDATE_API_SNAPSHOT=1 composer test:api-snapshot:update` (or the
   composer script `test:api-snapshot:update`) — **never** as an automatic side effect of a failing run.
5. Attach a `D#` (or plan-item) explaining the change, and a `SemVer bump` decision (§2) in the same commit/PR
   that regenerates the fixture.
6. If the change is breaking per §2, add the `upgrading.md` entry in the same commit (§3.2).

A fixture regenerated without a `D#`/bump rationale in the same change is a process violation of this policy,
not a valid freeze update — reviewers should treat an unexplained fixture diff as a request for the change's
justification, not just its mechanics.

**Complementary tooling (deferred, D20):** `roave/backward-compatibility-check` is a de-facto standard
tag-boundary BC checker (compares two git refs/tags, understands variance/covariance more precisely than a flat
reflection diff). It was evaluated and **deferred** — the reflection-snapshot mechanism above is sufficient for
0.x-strict freezing (its very over-sensitivity is a feature here, not a gap `roave` would need to fill) and
avoids adding a new dev-dependency this cycle. Revisit at the 1.0 tag boundary, where `roave`'s actual/intended-BC
distinction becomes more valuable than the snapshot's blunt drift-detection.

## 5. Cross-reference

- `root/api-surface.md` — the frozen surface, human-readable (P3.1).
- `root/contracts/facade-cutline.md` — the facade de-publication spec this cycle executed (P2.5/P3.1, D29).
- `root/known-limitations.md` — documented gaps that are *not* SemVer violations (out of scope, not blockers).
- `docs/introduction/upgrading.md` — the consolidated `0.2 → 0.3` breaking-change catalog, generated from this
  policy's §2 definition and the plan's Decision Log / Completion Notes (D10, D14–D18, D29).
