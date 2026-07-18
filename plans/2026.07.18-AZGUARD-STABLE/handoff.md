# HANDOFF — 2026-07-18 — after P0.0

**Next:** /task:plan-design 2026.07.18-AZGUARD-STABLE P0

| Параметр | Значение |
|:--|:--|
| Model | opus (пин команды plan-design; fable — легальный overshoot) |
| Thinking | high |
| Context | NEW SESSION — шаг-не-item |
| Суть | Design pass 2/3: детализировать фазу P0 (read-only аудит, 6 items) до DoR |

````
/task:plan-design 2026.07.18-AZGUARD-STABLE P0

РЕЖИМ: детализация фазы P0 (скелет → DoR); реопена нет
ВХОД: plans/2026.07.18-AZGUARD-STABLE/{plan.md, brief/00-brief.md, research/00-user-intent.md, findings/recon-api-surface-2026-07-18.md, findings/recon-test-ci-2026-07-18.md, findings/recon-vaulter-template-2026-07-18.md, phases/P0.md}
СКОУП: 1) заполнить все 16 полей items P0.1–P0.6 до «sonnet исполнит без вопросов» (P0 — read-only, Exec = manual fable/high по §3 Routing); 2) уточнить §3 Routing для P0; 3) проверить критерий заявленной оркестрации (оси P0.2–P0.5 scope-независимы — вероятен workflow-скрипт в workflows/); 4) refine-цикл + DoR-чеклист
НЕ ТРОГАТЬ: фазы P1–P5 (P1/P2 волатильны по D3 — детализация после закрытия P0); код пакетов; Обсуждение §2/§3 (Decision pending — решает владелец)
````

**Done:** Design pass 1/3 (режим `new`): каркас плана 2026.07.18-AZGUARD-STABLE —
brief/00-brief.md (слой 0), 3 recon-файла в findings/ (API-поверхность, vaulter-образец,
тест/CI), синтез research/00-user-intent.md (трассировка брифа), plan.md (D1–D6,
Routing-черновик, 2 развилки Decision pending), скелеты фаз P0–P5 с контракт-блоками
(26 items), open-questions.md (Q1 версия тега, Q2 состав docker-матрицы), ACTIVE →
2026.07.18-AZGUARD-STABLE. Судьба ветки refactor/plan-remainder сверена: PR #91 MERGED,
ветки нет.

**Remaining:** Design pass 2: детализация P0 → exec P0 (аудит) → pass 3: детализация
P1–P5 по фактам аудита → finish (roadmap) → plan-audit design → exec P1–P5.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md ·
plans/2026.07.18-AZGUARD-STABLE/phases/P0.md · brief/00-brief.md · findings/recon-*.md ·
research/00-user-intent.md

**Open risks:**
- Q1/Q2 (версия тега; состав docker-матрицы) — Decision pending, нужны владельцу до
  P5.2 / детализации P4 соответственно.
- P1/P2 волатильны (D3): полная детализация до exec невозможна — exec стартует при
  живых скелетах P1–P5 после детализации только P0 (санкционировано D3).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация P1–P5 (just-in-time по D3 / pass 3)
- open_questions: Q1 (версия тега 0.3.0?), Q2 (Postgres+Redis или +MySQL?) — см. open-questions.md
