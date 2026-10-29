# Security

This page states what AzGuard protects, where its trust boundaries lie, which properties are enforced and how, and what
it does not do. To report a vulnerability, see the repository [security policy](https://github.com/axiomasoft/azguard-private/blob/main/SECURITY.md).

## Model

**Assets.** Allow and deny decisions; grants, roles and dynamic permissions; tenant and scope isolation; audit
integrity; state and cache coherence; release artifacts and package identity; API and persisted compatibility.

**Adversaries and faults.** An unauthorized application user; a tenant user escaping to another tenant or scope; a
faulty or malicious extension, plugin or source; a compromised CI action or dependency; a maintainer credential
compromise; a stale cache or replica producing a wrong allow; an application developer bypassing the kernel.

**Trust boundaries.** Application to AzGuard API; plugin, source or policy to kernel; kernel to storage and cache;
primary to replica; monorepo to split repositories to Packagist; GitHub Actions to the release token; Filament UI to the
authorization backend.

## Properties and their enforcement

| Property | Enforced by |
|:--|:--|
| Error and unknown semantics are deny or an explicit failure (`VisibilityNotSupportedException` before the Builder is modified) | feature tests, `azguard:doctor` |
| Tenant and scope identity is never derived from unverified input | architecture tests, feature tests |
| Authorization writes are atomic with the state update and the audit participant | storage and engine tests |
| A stale code or config cache is never used by another build | build id (`AZGUARD_BUILD_ID`), `azguard:doctor --production` |
| Public API changes are detected before merge and release | compatibility gate (`composer api:compat`) |
| A release artifact is traceable to a reviewed commit | repository rulesets, release evidence, build attestation |
| An extension gets no hidden internal capability | SPI boundaries, architecture tests |

## Limitations

- There is no replica read-your-writes receipt: strict freshness is available through the primary by default.
- Writes of one panel are serialized through `panel_state`.
- There is no transactional outbox for an external audit sink; the built-in audit is transactional.
- The panel DSL is validated, not compiled immutable.
- JSON meta grant fields are not filterable in Filament (index and cursor semantics).
- AzGuard has a single maintainer: there is no independent code review and no second owner of the security inbox.
- Tag signing is optional.
- There is no new instrumentation API before 1.0.

## Incident response

1. Acknowledge the report within 2 business days and open a private advisory draft.
2. Reproduce, classify the severity and triage critical and high reports within 3 business days.
3. Fix in a private branch; add a failing test first.
4. Release a patch on every supported line, then publish the advisory.
5. Rotate any credential that could have been exposed; never rewrite a published tag.

## Emergency release

Use this checklist when a fix must ship outside the normal cadence.

1. Confirm the vulnerable range and the supported lines it touches.
2. Prepare the fix on a private branch attached to the advisory; keep the change minimal.
3. Run the full qualification on the exact commit that will be tagged.
4. Tag the patch release from the protected branch; the release workflow publishes the split repositories.
5. Verify the Packagist versions and a clean install on supported Laravel versions.
6. Publish the advisory, update `CHANGELOG.md`, and mark the vulnerable releases as superseded.
7. If the release itself is faulty, supersede it with a new patch release; never move or delete a tag.
