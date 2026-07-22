---
name: agent-security-super-skill
bucket: security
version: 0.1.0
description: AI agent security: prompt injection defense, skill/plugin validation, memory poisoning prevention, permission auditing, tool-use safety, exfiltration prevention, incident response. Use when installing skills, processing untrusted content, auditing permissions, validating memory, reviewing MCP configs, or hardening agents.
license: MIT
metadata:
  author: get-zeked
  version: '1.0'
adapters: [claude, cursor, fable]
sha256: ""
---

# Agent Security Super-Skill

Defensive security playbook for AI agents (Claude Code, Codex, Cursor, Copilot, any
LLM-powered autonomous system). The full ~102KB operations manual — checklists,
detection patterns/regexes, response procedures — lives in
[references/full.md](references/full.md). This file is a navigator only.

**Rule:** open `references/full.md` targeted — one section per concrete question.
Never load it whole: it will not fit a sane context budget.

**Related:** design-time secure-by-design / threat modeling → `architect/security-design`
(this skill is run-time / agent-ops).

## Section map (references/full.md)

- [1. Threat Model Overview](references/full.md#1-threat-model-overview) — attack types, severity matrix, sleeper/zombie agents, Rule of Two.
- [2. Content Ingestion Defense](references/full.md#2-content-ingestion-defense) — before processing ANY untrusted content: per-type detection checklists, pre-ingestion protocol, red-flag phrases, spotlighting.
- [3. Skill & Plugin Validation](references/full.md#3-skill--plugin-validation) — before installing a skill/plugin/MCP server: audit checklist, red flags, MCP tool scan, supply chain, typosquatting.
- [4. Memory Hygiene & Poisoning Prevention](references/full.md#4-memory-hygiene--poisoning-prevention) — validation/audit protocols, quarantine, safe updates.
- [5. Permission & Tool Safety](references/full.md#5-permission--tool-safety) — least privilege, escalation detection, exfiltration prevention, confused deputy, config protection.
- [6. Incident Response](references/full.md#6-incident-response) — suspected compromise: containment, classification, memory audit, skill quarantine, report template.
- [7. Hardening Checklists](references/full.md#7-hardening-checklists) — agent setup, pre-session, content processing, skill installation, memory audit.
- [8. Reference](references/full.md#8-reference) — OWASP LLM/Agentic Top 10, stats, tools, vendor guidance, CVEs, papers, source URLs.
- Appendices: [A — decision flowcharts](references/full.md#appendix-a-quick-decision-flowcharts) · [B — community security skills](references/full.md#appendix-b-community-security-skills--recommended-integration) · [C — secrets-never-in-context](references/full.md#appendix-c-secrets-never-in-context-rules).
