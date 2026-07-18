# Roadmap исполнения — 2026.07.18-AZGUARD-STABLE

<!-- Собран при завершении детализации всех фаз (design pass 3, P5); finish делает сквозной
     reconcile и при необходимости обновляет карту. Roadmap не переопределяет Routing §3
     (модели/effort) — только группирует запуски. Живой документ: закрытие фазы /
     re-design обновляет таблицу (+строка в Update Log плана). -->

**Обновлён:** 2026-07-18 · **Соответствует plan.md:** v0.3.5 (сверен `finish` — карта без изменений, контракты фаз не менялись)

## Карта исполнения

| Item | Batch | Запуск | Model/Thinking | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P0.1–P0.5 | — | ✅ закрыты | fable/high | — | исполнены workflow wf-azguard-stable-p0-audit.js (D8) |
| P0.6 | — | ✅ закрыт | fable/high | ✅ пройден (D9) | бэклог ремедиации утверждён |
| P1.1 | solo | plan-run (manual) | sonnet/high | — | Blocker C-01, security-контракт D10 а (default-fallback упразднён D27) — solo: смена контракта изоляции не смешивается с волной; перед запуском `/model sonnet` + `/effort high` |
| P1.2 | solo | plan-run (manual) | sonnet/high | — | 12 Major per-finding коммитами (D11) — solo: объём волны + security-состав |
| P1.3 | solo | plan-run (manual) | sonnet/medium | — | 14 Minor/Nit per-finding (D11) — solo: Exec=manual (строгость D11), сессия sonnet/medium |
| P1.4 | solo | plan-run (manual) | fable/high | — | adversarial review диффа фазы свежим контекстом — solo by design |
| P2.1–P2.10 | solo ×10 | plan-run (manual) | fable/high | — (развилки сняты D14–D18) | каждый item — отдельная fable/high-сессия: contract-класс, SemVer-breaking; порядок — phases/P2.md §Phase Context; объединять нельзя — каждый меняет публичные контракты, ревью full между items |
| P3.1 | solo | plan-run (manual) | sonnet/high | — | cut-line фасада (D19) — необратимо, solo; ресинк под D28 (roadmap-«fable» протухла — Audit P2 F3) |
| P3.2 | solo | plan-run (manual) | fable/high | — | snapshot-заморозка (D20) — гейт, включающий запрет дрейфа; solo |
| P3.3 | solo | plan-run (manual) | sonnet/high | — | SemVer-политика + UPGRADING (D21); solo; ресинк под D28 (roadmap-«fable» протухла — Audit P2 F3) |
| P4.1–P4.2 | B4 | plan-exec серия | sonnet/medium | — | стенд+БД-лейн одной сессией (P4.2 потребляет P4.1; ревью батча — full по максимуму участников) |
| P4.3 | solo | plan-exec | sonnet/medium | — | paratest — отдельный риск-профиль (parallel-изоляция), solo |
| P4.4 | solo | plan-exec | sonnet/medium | — | race-тесты C-05/C-14 — жёсткая эскалация §10 при реальном race-баге, solo |
| P4.5 | solo | plan-exec | sonnet/medium | — | mutation-ratchet — требует coverage-среды, честный замер; solo |
| P4.6 | solo | plan-exec | sonnet/medium | — | чистка дыр (light) — solo: не сцеплять с ratchet'ом, чтобы красный замер не блокировал механику |
| P4.7 | solo | plan-run (manual) | sonnet/high | — | collation-миграции MySQL (D24) — security-корректность, Exec=manual |
| P5.1 | solo | plan-run (manual) | fable/high | — | шаблон дорожки — канон флота, пишется от фактов ВСЕХ закрытых фаз; solo |
| P5.2–P5.3 | B5 | plan-exec серия | sonnet/medium | ✅ approve перед `git push origin v0.3.0` (внутри P5.2) | релиз + миграция root/→docs одной сессией; тег НЕ пушится без явного approve владельца (D25) |

После P5.3: `/task:plan-close archive 2026.07.18-AZGUARD-STABLE` (post-plan, D26).
Между фазами: `/task:plan-close <ID> Pn` + `/task:plan-audit <ID> Pn` по протоколу.

## Готовые launch-block'и групп

### P1 — ремедиация (manual-сессии, по одной волне)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high (P1.1/P1.2) · medium (P1.3) — по Routing §3 |
| Context | Холодный старт: plan.md → phases/P1.md → handoff.md |
| Суть | Волны W0→W1→W2 последовательно, per-finding коммиты (D11); P1.4 — fable/high |

```
/task:plan-run 2026.07.18-AZGUARD-STABLE P1.1
```

### P2/P3/P5.1 — contract-items (manual, fable/high)

| Параметр | Значение |
|:--|:--|
| Model | fable (opus-класс) |
| Thinking | high — public-contract/canon-класс |
| Context | Холодный старт: plan.md → phases/Pn.md → research/03-p2-canon.md (для P2) |
| Суть | Каждый item — отдельная сессия `/task:plan-run`; ритуал `/model` + `/effort` перед запуском |

```
/task:plan-run 2026.07.18-AZGUARD-STABLE P2.1
```

### B4 — тест-инфраструктура (plan-exec серия)

| Параметр | Значение |
|:--|:--|
| Model | sonnet (пин команды) |
| Thinking | medium (пин команды) |
| Context | Холодный старт: plan.md → phases/P4.md → handoff.md |
| Суть | Серия P4.1–P4.2 одной сессией (стенд → БД-лейн), полный §8-цикл на каждом |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P4.1 P4.2
```

### B5 — релиз + закрытие (plan-exec серия, гейт владельца внутри)

| Параметр | Значение |
|:--|:--|
| Model | sonnet (пин команды) |
| Thinking | medium (пин команды) |
| Context | Холодный старт: plan.md D22/D25/D26 → phases/P5.md → findings/P5-rag-release-guard-2026-07-18.md |
| Суть | P5.2 (релиз: тег ТОЛЬКО после approve владельца) → P5.3 (миграция root/→docs, финальный handoff) |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P5.2 P5.3
```

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| P0.6 | бэклог ремедиации (волны/кластеры/отклонения) | ✅ пройден (D9) — P1/P2 разблокированы |
| P5.2 | approve перед `git push origin v0.3.0` (необратимая публикация тега) | завершение B5 (тег, GH Release, P5.3) |
