# HANDOFF — 2026-07-18 — after P0

**Next:** «ЗАПУСК ВРУЧНУЮ: fable/high» — workflow-прогон P0.1–P0.5 + закрытие items по §8

| Параметр | Значение |
|:--|:--|
| Model | fable (Routing §3: fable/high; агенты workflow наследуют модель сессии) |
| Thinking | high |
| Context | NEW SESSION — Workflow-оркестрован |
| Суть | Exec P0.1–P0.5: аудит через wf-azguard-stable-p0-audit.js (RAG → 4 оси), затем закрыть items |

````
Исполни items P0.1–P0.5 плана 2026.07.18-AZGUARD-STABLE. Сессия обязана быть fable/high (гейт §3 Routing; undershoot запрещён).

РЕЖИМ: exec оркестрованной фазы P0 (D8) — read-only аудит, код/тесты/docs/CI не менять
ВХОД: plans/2026.07.18-AZGUARD-STABLE/{plan.md, phases/P0.md, handoff.md, artifacts/P0-finding-template.md}
СКОУП:
1) Прочитай файлы ВХОДа.
2) Запусти Workflow({scriptPath: "plans/2026.07.18-AZGUARD-STABLE/workflows/wf-azguard-stable-p0-audit.js"}) — P0.1 (RAG) → барьер → параллельно P0.2–P0.5; агенты пишут findings-файлы, БЕЗ git-команд.
3) По завершении прогони Validation каждого item из phases/P0.md; красное — чинить в findings-файлах (не в коде).
4) Закрой P0.1–P0.5 последовательно по протоколу §8: item-commit файлов из Files item'а → bookkeeping-commit (git add только явными путями; -A/-a запрещены).
5) Финальный handoff: Next → «ЗАПУСК ВРУЧНУЮ: fable/high» с голым промптом P0.6 (синтез REGISTER + research/02-backlog.md + блокирующий гейт владельца).
НЕ ТРОГАТЬ: код пакетов/тесты/CI (P0 read-only); фазы P1–P5 (P1/P2 волатильны по D3); Обсуждение §2/§3 (Decision pending — владелец); item P0.6 (отдельная сессия после гейта Validation осей)
````

**Done:** Design pass 2/3: фаза P0 детализирована до DoR — 6 items со всеми 16 полями
(закрытые чеклисты C-A1..C-A11 / C-B1..C-B10 / C-C1..C-C10 / C-D1..C-D12, grep-Validation,
код-якоря сверены с репо), канон находок `artifacts/P0-finding-template.md`, workflow
`workflows/wf-azguard-stable-p0-audit.js` (D8), Routing P0 уточнён (P0.1–P0.5 workflow-сессия ·
P0.6 solo), D7 (бэклог → research/02-backlog.md, контракты P0/P1/P2 reconciled), plan v0.2.0.

**Remaining:** Exec P0 (workflow P0.1–P0.5 → P0.6 синтез + гейт владельца) → design pass 3:
детализация P1–P5 по фактам аудита (P1/P2 из REGISTER/бэклога по D3) → finish (roadmap) →
plan-audit design → exec P1–P5.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.2.0, D1–D8) ·
phases/P0.md (спецификации items) · artifacts/P0-finding-template.md (формат находок) ·
workflows/wf-azguard-stable-p0-audit.js · brief/00-brief.md · findings/recon-*.md ·
research/{00-user-intent,01-fluent-api-priors}.md · findings/P0-rag-fluent-dx-preseed.md

**Open risks:**
- Q1/Q2 (версия тега; состав docker-матрицы) — Decision pending, нужны владельцу до
  P5.2 / детализации P4 соответственно (open-questions.md).
- context7 в workflow-агенте P0.1: ключ прописан, но первый запуск из свежей MCP-сессии —
  при недоступности агент честно спускается по RAG-лестнице ([UNVERIFIED] вместо ✅).
- Гейт владельца на бэклог (P0.6) — блокирующий: без ответа item не закрывается.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация P1–P5 (pass 3, после exec P0 — D3); roadmap.md (собирается в finish)
- open_questions: Q1 (версия тега 0.3.0?), Q2 (Postgres+Redis или +MySQL?) — см. open-questions.md
