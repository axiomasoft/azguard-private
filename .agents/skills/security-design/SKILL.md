---
name: security-design
bucket: architect
version: 0.2.0
description: "Data classification, auth strategy, RBAC/ABAC, OWASP Top 10, GDPR, STRIDE threat modeling, ops security checklist"
risk: draft
persona: architect
tags: [security, compliance, auth, owasp, threat-modeling, rbac, gdpr, privacy, stride]
requires: [architecture]
produces_for: []
outputs: ["docs/03_Dev/Security_Design.md"]
snippets: ["stride-threat-model.md", "ops-security-checklist.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Security Design

Use when: designing a system with user data, finances, authentication, APIs. Run **in parallel with** `architecture.md`, not after.

Rule: **if the product handles PII, money, or third-party integrations → this skill is mandatory.**

## MVP-gate: security review before first user

- Vulnerabilities are invisible until exploited. Shipping a live MVP to real users = real data/exposure/consequences.
- **Security review before any user touches the app = minimum responsible threshold** (MVP-stage exit criterion, not "later"). First-pass:
  - code review: authentication & session handling, data exposure in API responses, input validation & injection, dependencies with known vulnerabilities;
  - any finding touching auth / secrets / data handling → mandatory human review;
  - AI scan (e.g. Claude Code Security) = aid, **not a replacement** for qualified security review at high stakes.

## Step 1. Data classification

Identify what is stored and its class:

| Class | Examples | Requirements |
|:---|:---|:---|
| **Public** | Public content, descriptions | No restrictions |
| **Internal** | Logs, metrics, aggregates | Access only within the system |
| **PII** | Email, name, phone, IP | Encryption at rest, minimal storage |
| **Sensitive PII** | Passport, address, date of birth | Encryption + audit + right-to-delete |
| **Financial** | Cards, bank accounts, transactions | PCI DSS scope, never store raw |
| **Credentials** | Passwords, tokens, API keys | Hash/vault only, never in logs |

## Step 2. Auth strategy

Choose and justify the authentication scheme:

| Scheme | Fits when | Does not fit when |
|:---|:---|:---|
| **JWT (stateless)** | Microservices, mobile clients, API | Immediate token revoke needed |
| **Session cookie** | Monolith, web-first, SSR | Scaling without sticky session |
| **OAuth2 + OIDC** | "Sign in with Google/GitHub", B2B SSO | Simple internal tools |
| **API Key** | Machine-to-machine, webhooks | User access from browser |
| **mTLS** | Service-to-service inside infrastructure | Client applications |

**Rule:** each client type (web, mobile, API, service-to-service) gets its own scheme. Do not mix.

## Step 3. Authorization

Pick the model:

- **RBAC** (Role-Based): user has role → role has permissions. Fits most SaaS.
- **ABAC** (Attribute-Based): access depends on object and subject attributes. For complex multi-tenant scenarios.
- **Ownership-based**: user sees only their own. Simplest, works for B2C MVP.

Check: any scenario where `User A` can get `User B`'s data via direct ID in URL or API param? → **IDOR** (Insecure Direct Object Reference) — most common vulnerability.

## Step 4. OWASP Top 10 — quick checklist

Apply to the specific product type:

| # | Threat | Check for this product |
|:---|:---|:---|
| A01 | Broken Access Control | IDOR checked? Roles cover all endpoints? |
| A02 | Cryptographic Failures | PII encrypted at rest? TLS everywhere? |
| A03 | Injection | SQL via ORM? Input validated? |
| A04 | Insecure Design | Threat model done before code? |
| A05 | Security Misconfiguration | Default credentials changed? Debug off in prod? |
| A06 | Vulnerable Components | Dependencies scanned (Dependabot/Snyk)? |
| A07 | Auth Failures | Brute-force protection? Password reset secure? |
| A08 | Software Integrity | CI signed? Supply chain verified? |
| A09 | Logging Failures | Logs free of PII/credentials? Audit trail exists? |
| A10 | SSRF | External URL requests validated? |

## Step 5. Privacy-by-Design (GDPR minimum)

Mandatory if the product handles EU users or PII:

- [ ] **Consent:** explicit consent before collecting data (not pre-checked checkbox)
- [ ] **Data minimization:** collect only what the function actually needs
- [ ] **Right to delete:** endpoint/flow to delete account + all data
- [ ] **Data portability:** user can download their data
- [ ] **Retention policy:** after how many days delete inactive data?
- [ ] **Third-party sharing:** which data goes to analytics, ads, partners?
- [ ] **Privacy policy:** honestly describes real data collection

## Step 6. Secrets Management

| Where NOT to store | Where to store |
|:---|:---|
| `.env` in git repo | `.env` local only + `.gitignore` |
| Hardcoded in code | Environment variables in deployment |
| CI/CD logs | Secrets manager (Vault, AWS SSM, GitHub Secrets) |
| Client code (JS) | Server side only |

**Third-party API keys:** rotate at least once a year. Scope — minimal permissions.

## Step 7. Threat Modeling (simplified STRIDE)

For each critical component go through:

| Threat | Question |
|:---|:---|
| **S**poofing | Can an attacker impersonate a legitimate user? |
| **T**ampering | Can someone alter data in transit or at rest? |
| **R**epudiation | Can you prove who performed an action? (audit log) |
| **I**nformation Disclosure | What data can leak and through which channel? |
| **D**enial of Service | Rate limiting present? Flood protection? |
| **E**levation of Privilege | Can a User gain Admin rights? |

## Which snippet to open

| Situation | File |
|:---|:---|
| Going through STRIDE per component (Step 7) — need full template | `snippets/stride-threat-model.md` |
| Setting up/reviewing app, DB, servers, creds, backups — operational rules (per Spatie) | `snippets/ops-security-checklist.md` |

## Output format in the document

Create in `docs/03_Dev/Security_Design.md`:

```markdown
# Security Design: ProjectName

## Классификация данных
[таблица]

## Auth-схема
[выбранная схема + аргументация]

## Авторизация
[модель + IDOR проверка]

## OWASP чеклист
[таблица со статусами]

## Privacy / GDPR
[чеклист]

## Secrets Management
[правила для данного проекта]

## Threat Model
[STRIDE по ключевым компонентам]

## Открытые вопросы безопасности
[что ещё не решено]
```

## What the agent adds itself

- IDOR scenarios not mentioned but implied by the API structure
- Warning if the chosen auth method does not match the client type
- Flag if BRD mentions data falling under GDPR but Privacy section is missing

## Hard prohibitions

DO NOT:
- Skip Security Design for products with users
- Propose storing passwords in plaintext or MD5
- Recommend storing API keys in client code
- Skip IDOR check for APIs with user resources
- Mix Auth (who are you?) with Authz (what may you do?)

## Related skills

- `architect/architecture` — run in parallel: security = constraints affecting architecture from the start.
- `architect/legal-compliance` — GDPR/AI Act checklist that feeds requirements here (input of this skill).
- `architect/api-design` — auth scheme by client type and rate limiting for API contracts.
- `architect/observability-design` — PII policy in logs, masking, audit trail.
- `oss-dev/dependency-audit` — supply-chain/SBOM/vuln scan, feeds OWASP A06 (input of this skill).
- `laravel-auth/laravel-security-audit` — code-level implementation audit (raw SQL, mass assignment, XSS), feeds this design (input).
- `azguard/azguard`, `laravel-auth/laravel-permissions`, `laravel-auth/attribute-authorization` — RBAC/ABAC implementation of the chosen authorization model.
- `security/security` — external defensive playbook (OWASP LLM Top 10, NIST AI RMF).

<!-- ru-source-sha256: d5700bc88f4010de93d868d9541d766081a95971a9fa85cba964a10a1e80ef85 -->
