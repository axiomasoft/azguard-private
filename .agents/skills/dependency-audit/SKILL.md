---
name: dependency-audit
bucket: oss-dev
version: 0.2.0
description: Dependency audit — lockfile sanity, vuln scan, license compatibility, supply-chain risk, SBOM
risk: draft
persona: oss-dev
tags: [oss, compliance, security, dependencies, php]
requires: [oss-development]
produces_for: [security-design, release-engineering]
outputs: ["ProjectName/Dependency_Audit.md", "ProjectName/SBOM.json"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Dependency Audit

Apply when: **before first OSS publish**, **before MAJOR release**, or **on trigger** — security advisory, license question from a potential enterprise consumer, supply-chain alert.

Must run **after `oss-development`** (has `package.json` / `composer.json` / `pubspec.yaml`). Produces input for `security-design` (compliance pipeline) and `release-engineering` (SBOM as release artefact).

## When NOT to apply
- Pet-project with no deps or 1–2 stdlib deps — overkill.
- Audit ran < 7 days ago and lockfile unchanged — reuse previous report.
- Closed package without publish — simplified check, not full audit.

## 5 audit dimensions
5 dimensions (vuln-scan, licenses, supply-chain, freshness, SBOM) detailed in [references/audit-dimensions.md](references/audit-dimensions.md).

## Agent adds itself
- **Renovate / Dependabot config.** Min: `.github/dependabot.yml` with group for minor/patch updates.
- **Provenance.** npm `--provenance` (via GitHub OIDC) — outside audit scope but tied to CI release pipeline. Mention in report.
- **Reproducible builds.** Lockfile + frozen install (`npm ci` / `composer install --no-dev --prefer-dist --no-progress`).
- **License-of-licenses.** Check direct AND **transitive** deps — typical GPL leak.
- **Update version policy.** Auto-merge for patches from trusted maintainers OK; majors — manual review.

## Output files

### `ProjectName/Dependency_Audit.md`
```markdown
---
project: [ProjectName]
audit_date: YYYY-MM-DD
audit_scope: production | production + dev
tooling: npm@X, composer@Y, ...
---

# Dependency Audit — [ProjectName]

## Сводка
- Direct deps: N
- Transitive deps: M
- Lockfile committed: yes/no
- License conflicts: 0
- Open vulnerabilities: 0 critical / 0 high / X moderate / Y low

## Lockfile
- Status: clean / dirty
- Phantom deps: ...
- Duplicates: ...

## Vulnerabilities
| Severity | Package | Version | Advisory | Status (fixed / accepted / blocked) |
|:---|:---|:---|:---|:---|
| ...     | ...     | ...     | GHSA-XXX | fixed in 1.2.4 |

## License inventory
| License | Count | Packages |
|:---|:---|:---|
| MIT | 142 | ... |
| Apache-2.0 | 12 | ... |
| ISC | 8 | ... |

**Conflicts / red flags:** [список или «нет»]

## Supply-chain risk
- Bus-factor red flags: ...
- Recent ownership changes: ...
- Postinstall scripts allowlist: ...

## SBOM
→ `ProjectName/SBOM.json` (CycloneDX 1.5)

## Action items
- [ ] ...
- [ ] ...

## Next audit
- Триггер: следующий MAJOR / security advisory / 90 дней (что раньше)
```

### `ProjectName/SBOM.json`
Generated file, not hand-edited. Regenerated on every release.

## Links
- [references/composer.md](references/composer.md) — composer specifics: commands per dimension, roave/security-advisories, reproducible installs, composer pitfalls, SBOM for PHP.
- `oss-dev/oss-development` — prerequisite: gives manifest (`composer.json`/`package.json`) being audited.
- `oss-dev/release-engineering` — consumer: SBOM published as release artefact.
- `oss-dev/oss-governance` — project LICENSE choice; dependency license compatibility is here.
- `architect/security-design` — consumer: vuln/supply-chain findings go to compliance pipeline.
- `php-packages/laravel-package-compatibility` — composer constraints, PHP/Laravel/Testbench versions for PHP packages.
- `php/static-analysis` — Pint/PHPStan/Rector: code quality alongside dependency audit.

## Hard prohibitions
NEVER:
- Release `v1.0+` without audit report
- Ignore `critical`/`high` vulnerabilities
- Accept GPL/AGPL/BUSL packages as deps without explicit justification and approval
- Use a package without explicit SPDX license
- Allow postinstall scripts from untrusted maintainers without allowlist
- Skip committing lockfile in an app (for libraries — see discussion above)
- Rely on `npm audit` as the only tool — it does not catch supply-chain

<!-- ru-source-sha256: 8af7db936e3aad76b45e08d5cbf27fe0be1da64d840fdf688e63efaa9f9b4205 -->
