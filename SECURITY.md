# Security Policy

## Reporting a Vulnerability

Canonical private contact: [dv.vostrikov@gmail.com](mailto:dv.vostrikov@gmail.com).
Please **do not** open a public issue or pull request with vulnerability details.
This is the maintainer contact also recorded in both packages' Composer author metadata.
If a mail link does not work, copy the address into your mail client. If there is no acknowledgement,
retry the same address after 3 business days; do not include details in a public issue.

As checked on 2026-11-03, the intended `axiomasoft/azguard` repository is private and its reporting URL
returns HTTP 404 to an unauthenticated visitor. Private reporting enablement could not be verified.
The intended future channel is `https://github.com/axiomasoft/azguard/security/advisories/new`;
use email until that repository is public and private vulnerability reporting has been verified as enabled
for outside researchers. An administrator's access alone does not verify a public reporting channel.
No test vulnerability report was sent. See the dated read-only inventory in [RELEASING.md](RELEASING.md#security-channel-and-publication-inventory-2026-11-03).

Include where possible:

- affected package(s), version(s) or commit, and relevant configuration;
- impact, reproduction steps and a minimal proof of concept using synthetic data;
- known exploitation and any mitigation already applied;
- whether and how you want to be credited.

Do not send production credentials, signing keys or personal data. For sensitive evidence, first ask by email
to agree a suitable encrypted exchange; no project encryption key is currently advertised.

## Supported Versions

This policy covers `axiomasoft/azguard` and `axiomasoft/azguard-filament` together.

| Version | Security maintenance |
|:--|:--|
| `2.x` (latest stable minor) | Supported once published; use the latest patch of that minor |
| `main` | Best-effort development fixes; not a stable release |
| `2.x` prereleases | Evaluation only; fixes may require the next prerelease |
| `1.x` | Publication/support status unverified; no maintained line or LTS commitment is established |
| `< 1.0` | Not supported |

The planned line is `2.0.0-rc.1`, then `2.0.0`; a local Composer path version does not establish publication.
The 2026-11-03 inventory found monorepo tags `v0.3.0` and `v0.7.0` and release `v0.3.0`, but did not establish
a published 1.x or stable 2.x Composer artifact. Split/Packagist HTTP 404 responses do not prove absence or
non-consumption. Report suspected issues in older installations too: the maintainer will triage their impact,
but this is not a promise of a backport. Any newly verified published 1.x inventory requires an explicit support
decision and policy update before announcing maintenance. See [release inventory](RELEASING.md#security-channel-and-publication-inventory-2026-11-03)
and the [2.0 upgrade guide](docs/guides/upgrading-2.md) before migrating an existing installation.

There is no fixed LTS period, end-of-life date or contractual response/patch SLA. AzGuard has a single maintainer;
availability and severity determine the work schedule. Support changes are announced in this policy and release notes.

## Response Targets

| Step | Best-effort target |
|:--|:--|
| Initial acknowledgement | within 3 business days |
| Triage | Prioritize critical/high severity, active exploitation and reachable impact; agree the next update after acknowledgement |
| Remediation | By severity and exploitability; communicate progress privately by email or a verified advisory thread |

These are targets, not guarantees. Business days are Monday–Friday excluding the maintainer's local public holidays.

## Coordinated disclosure and incident response

1. Acknowledge privately, reproduce and agree the affected range, severity, mitigation and next update with the reporter.
2. Keep evidence and fixes private with access limited to the reporter and people needed for remediation. Use a private
   advisory draft/private fork where available; a branch in a public repository is not an embargo boundary.
3. For an accepted vulnerability, prepare a GHSA when available and request a CVE through GitHub or another CNA when
   eligible. CVE assignment is not guaranteed and does not block mitigation. Record identifiers, affected/fixed versions,
   workarounds and credit (or anonymity) in the advisory; follow up with identifiers if assigned later.
4. Add a regression test. Prepare a patch for each affected supported stable line; on `main` or an unpublished line,
   prepare the fix for the next prerelease. Prioritize an urgent minimal patch for critical/high or exploited issues;
   schedule lower severity fixes by impact. Prereleases do not substitute for a fix on an affected supported stable line.
5. Agree the embargo and disclosure date with the reporter, normally the day verified fixed artifacts are available.
   There is no fixed embargo duration. If exploitation, a leak or a delayed fix makes earlier warning necessary,
   coordinate a limited advisory and mitigation with the reporter rather than promising indefinite secrecy.
6. Qualify the exact release commit and verify both package artifacts. Publish the advisory, mitigations, vulnerable
   range, fixed versions and credit at the agreed time, and update `CHANGELOG.md`. A published tag is never moved or
   deleted: supersede vulnerable or faulty artifacts with a new release.

For leaked release credentials, signing material or cache keys, contain and rotate promptly; do not send the secret
in the report. See the [key compromise and release checklist](docs/security.md#key-compromise-response) and the
[emergency release checklist](docs/advanced/security.md#emergency-release).
