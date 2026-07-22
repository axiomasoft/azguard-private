---
name: legal-compliance
bucket: architect
version: 0.1.0
description: GDPR, CCPA, AI Act, DMCA, COPPA, DSA — pre-launch regulatory checklist
risk: draft
persona: architect
tags: [compliance, gdpr, privacy, ai-act, regulatory]
requires: [architecture]
produces_for: [security-design]
outputs: ["docs/03_Dev/Legal_Compliance.md", "docs/01_Business/Privacy_Policy_Draft.md", "docs/01_Business/Terms_of_Service_Draft.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Legal Compliance

Apply when: product handles user data, AI models, UGC, child audience, finance or payments — and needs a regulatory checklist **before** launch.

**Does not replace a lawyer.** Prepares a structured checklist + drafts for later lawyer review. No lawyer → no release.

## When NOT to apply
- Pure OSS package, no data/service (library) — N/A. With telemetry — applicable.
- B2B exclusively via signed contracts + DPA — covered by contract, not public policy.
- Internal tool, no external users — minimal scope.
- No architecture → refuse (can't assess compliance without knowing where data is stored).

## 6 regulatory regimes (consider all)

| Regime | Applies when |
|:---|:---|
| **GDPR** (EU) | ≥1 EU user |
| **CCPA / CPRA** (California) | > 100k California users OR > $25M revenue |
| **AI Act** (EU) | AI system with risk classification |
| **DMCA** (US) | UGC with potential copyright infringement |
| **COPPA** (US) | Users < 13 |
| **DSA** (EU) | UGC platform, > 45M EU users or just a platform |

Additional if needed: PSD2 (EU payments), PCI-DSS (card storage), HIPAA (medical), SOC 2 (B2B SaaS requirement).

## GDPR standard checklist (minimum baseline)
Full GDPR baseline checklist (legal bases, subject rights, retention, DPA) — [references/gdpr-checklist.md](references/gdpr-checklist.md).

## AI Act — classification (for AI projects)

| Class | What | Requirements |
|:---|:---|:---|
| **Unacceptable risk** | Social scoring, manipulation | Prohibited |
| **High risk** | Education, employment, credit | Conformity assessment, registration, human oversight, transparency |
| **Limited risk** | Chatbots, deepfakes | Transparency: "you're talking to AI" |
| **Minimal risk** | Spam filters, recommendations | Voluntary codes of conduct |

High-risk → separate doc `docs/03_Dev/AI_Act_Conformity.md` (not covered here; separate lawyer scenario).

## DMCA (for UGC platforms) — mandatory minimum
- **Designated DMCA agent** registered in US Copyright Office ($6 fee, renew every 3 years)
- **Takedown notice flow** — UI/email for rights holders
- **Counter-notice flow** — for users to contest takedown
- **Repeat infringer policy** — auto-ban after N strikes, published in ToS
- **Logging** — who, when, what removed — for litigation

## COPPA (if users < 13)
If **not** targeting children and no "actual knowledge" of their presence → may block <13 at registration.
If targeting children:
- Verifiable parental consent (parent's payment card, ID scan, video-call — limited options)
- Child data minimization
- No behavioural ads to children
- Specific Privacy Policy for child audience

Alternative to overhead: age gate at 13+.

## Agent adds itself
- **Risk-rating per project** — overall risk: low / medium / high / critical (e.g. PSD2+AI Act+GDPR = high; GDPR+affiliate disclosure = medium; OSS package = low).
- **Data flow diagram** — from architecture, Mermaid, mark EU/US borders.
- **Sub-processor list** — extract all external vendors (AWS, Stripe, OpenAI, Twilio) from architecture → future public list for GDPR.
- **Cross-border transfer mechanism** — if data flows EU → US, state mechanism (SCC / EU-US Data Privacy Framework).
- **Country-specific checklist** — RU (152-FZ, PD localization), Brazil (LGPD), UK (UK GDPR + ICO) — if ICP includes.

## Output file structure

### `docs/03_Dev/Legal_Compliance.md`

```markdown
---
project: [ProjectName]
stage: legal-compliance
based_on: docs/03_Dev/Architecture_[Name].md
overall_risk: low | medium | high | critical
requires_lawyer_review: true
---

# Legal Compliance — [ProjectName]

## Применимые режимы (агент)
- GDPR: [yes/no/conditional] — обоснование
- CCPA: ...
- AI Act: [класс]
- DMCA: ...
- COPPA: ...
- DSA: ...
- Country-specific: [список]

## Overall risk: [уровень] (агент)

## Data inventory (Article 30)
[таблица]

## Data flow diagram
```mermaid
[диаграмма потоков данных с EU/US границами]
```

## Sub-processors
[список с DPA-статусом]

## GDPR-чеклист
- [ ] Legal basis для каждой категории данных
- [ ] User rights: access / rectification / erasure / portability / objection
- [ ] Consent management
- [ ] Privacy Policy (draft)
- [ ] ToS (draft)
- [ ] DPA подписаны со всеми sub-processors
- [ ] Breach response plan

## AI Act (если применимо)
- Классификация: ...
- Transparency обязательства: ...
- Human oversight: ...

## DMCA (если UGC)
- Designated agent registered: yes/no
- Takedown flow: ...
- Counter-notice flow: ...
- Repeat infringer policy: ...

## COPPA (если <13 users)
- Возрастной gate / verifiable parental consent: ...

## Открытые вопросы для юриста
- ...

## Следующие шаги
1. Передать draft Privacy Policy и ToS юристу
2. Подписать DPA с [список процессоров]
3. Зарегистрировать DMCA agent (если применимо)
4. AI Act conformity assessment для high-risk компонентов
```

### `docs/01_Business/Privacy_Policy_Draft.md`
Standard GDPR-compliant Privacy Policy template with placeholders for contacts + jurisdiction. **Marked DRAFT** — don't publish without lawyer.

### `docs/01_Business/Terms_of_Service_Draft.md`
Basic ToS draft. Same — DRAFT until lawyer.

## Hard prohibitions — NEVER
- Publish Privacy Policy / ToS without lawyer
- Launch high-risk AI without conformity assessment
- Ignore GDPR "because we just started" — fine €20M / 4% turnover
- Claim "no PII" if collecting email or IP — already PII in EU
- Launch UGC without DMCA flow in US
- Store data of children < 13 without COPPA flow in US
- Do crypto/fintech without legal consultation on AML/KYC
- "Compliance later" — retrofit usually 10× design-time cost

## Related skills
- `architect/architecture` — data storage + vendor map as compliance basis (precondition).
- `architect/security-design` — GDPR privacy-by-design, data classification, retention; this checklist feeds its requirements.
- `architect/eval-design` — for AI Act high/limited-risk: eval harness as part of conformity + transparency.
- `architect/observability-design` — audit trail + log retention under Article 30 / breach-response.
- `legal/legal` — external body on contracts/NDA/compliance (analysis, not mirror).

<!-- ru-source-sha256: 4e3c90dc8145ce8a7ac7f2d1c695523b2766e56402823bd009a9f31707cb44ec -->
