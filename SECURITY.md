# Security Policy

## Supported Versions

| Version | Supported |
|:--|:--|
| `1.x` (latest minor) | yes |
| `main` | yes |
| `< 1.0` | no |

Security fixes are released on every supported line. A published tag is never moved or deleted: a vulnerable release is
superseded by a patch release and marked in the advisory and in `CHANGELOG.md`.

## Reporting a Vulnerability

Please **do not** open a public issue for security problems.

Report privately through GitHub private vulnerability reporting:
<https://github.com/axiomasoft/azguard-private/security/advisories/new>

If that channel is unavailable, send an email to **dv.vostrikov@gmail.com**. The private reporting inbox is monitored by
the repository admin; the email is the fallback channel. AzGuard has a single maintainer, which is an accepted residual
risk (see [Limitations](docs/advanced/security.md#limitations)).

Include where possible:

- a description of the issue and its impact,
- affected package(s) and version(s),
- reproduction steps or a proof of concept,
- whether you want to be credited, and under which name.

## Response Targets

| Step | Target |
|:--|:--|
| Acknowledgement | within 2 business days |
| Initial triage of critical and high reports | within 3 business days |
| Remediation | by severity and exploitability; reported to you in the advisory thread |

Disclosure is coordinated: nothing is published before a fixed release exists.

## Process

1. The report is received as a GitHub Security Advisory draft (GHSA).
2. A CVE is requested through GitHub when the issue is accepted.
3. The fix is prepared in a private fork or branch attached to the advisory.
4. A patch release ships on every supported line.
5. The advisory is published with credit, or anonymously on request.
6. Embargo: the reporter and the maintainer agree on a publication date after the release; the default is the release day.

Releases that must ship urgently follow the
[emergency release checklist](docs/advanced/security.md#emergency-release).
