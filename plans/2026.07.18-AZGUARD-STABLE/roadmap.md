# Roadmap исполнения — 2026.07.18-AZGUARD-STABLE

<!-- Собран при завершении детализации всех фаз (design pass 3, P5); finish делает сквозной
     reconcile и при необходимости обновляет карту. Roadmap не переопределяет Routing §3
     (модели/effort) — только группирует запуски. Живой документ: закрытие фазы /
     re-design обновляет таблицу (+строка в Update Log плана). -->

**Обновлён:** 2026-07-21 · **Соответствует plan.md:** v0.3.23 (D33/D34 — Codex-only
execution contract, Luna/Terra/Sol, checkpoints ревью, повторная приёмка dirty P4.8)

**Codex-проекция:** economy = GPT-5.6 Luna · implementation = GPT-5.6 Terra ·
frontier = GPT-5.6 Sol. Семантический route из plan.md важнее имени provider-модели.
Обязательный вход каждого незавершённого item: `research/05-codex-execution-contract.md`.
Требование запуска: `task@swissknifeman` 0.3.0+ и новая Codex-сессия после его обновления.

## Карта исполнения

| Item | Batch | Запуск | Model/Thinking | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P0.1–P0.5 | — | ✅ закрыты | fable/high | — | исполнены workflow wf-azguard-stable-p0-audit.js (D8) |
| P0.6 | — | ✅ закрыт | fable/high | ✅ пройден (D9) | бэклог ремедиации утверждён |
| P1.1–P1.4 | — | ✅ фаза закрыта | historical Claude routes | — | терминальность и deviations — phases/P1.md; не перезапускать |
| P2.1–P2.10 | — | ✅ фаза закрыта | historical Claude routes | — | терминальность и deviations — phases/P2.md; не перезапускать |
| P3.1–P3.3 | — | ✅ фаза закрыта | historical Claude routes | — | поверхность заморожена; не перезапускать |
| P4.1 | — | ✅ закрыт | sonnet/medium | — | docker-стенд PG16/MySQL8/Redis7 |
| P4.2 | — | ✅ закрыт | sonnet/medium | — | **re-scope D31**: коммит БД-лейн-харнесса + фикс тест-фикстуры expires_at; CI/green отложены в P4.10 |
| P4.8 | solo | plan-run (manual) | implementation/high → Terra/high | — | **ПРОДОЛЖИТЬ существующий dirty diff**, не начинать заново; затем независимый Sol/high review до закрытия |
| P4.7 | solo | plan-run (manual) | implementation/high → Terra/high | — | key-length+collation 000002/000010; затем независимый Sol/high review до закрытия |
| P4.9–P4.10 | B6 | plan-exec серия | implementation/medium → Terra/medium | — | LIKE-escape → green-proof+CI; один Sol/high review checkpoint на итог B6 |
| P4.3 | solo | plan-exec | implementation/medium → Terra/medium | — | paratest, отдельный риск-профиль; full review |
| P4.4 | solo | plan-exec | implementation/medium → Terra/medium | — | race C-05/C-14; независимый Sol/high concurrency-review; реальный race → §10 |
| P4.5 | solo | plan-exec | implementation/medium → Terra/medium | — | mutation-ratchet по честному baseline; full review |
| P4.6 | solo | plan-exec | implementation/medium → Terra/medium | — | механическая чистка дыр; light review |
| P5.1 | solo | plan-run (manual) | frontier/high → Sol/high | — | канон флота от фактов всех закрытых фаз; solo |
| P5.2–P5.3 | B5 | plan-exec серия | implementation/medium → Terra/medium | ✅ approve перед `git push origin v0.3.0` (внутри P5.2) | релиз+docs; тег не пушится без approve D25 |

После P5.3: `$ task:plan-close archive 2026.07.18-AZGUARD-STABLE` (post-plan, D26).
Между фазами: `$ task:plan-close <ID> Pn`, затем **новая Sol/xhigh-сессия**
`$ task:plan-audit <ID> Pn` по протоколу.

## Готовые launch-block'и групп

### P5.1 — канон флота (manual, frontier/high)

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Codex | GPT-5.6 Sol |
| Effort | high — канон флота |
| Context | same-session — item; cold-start reads: research/05 → plan.md → phases/P5.md → handoff.md → Completion Notes всех фаз |
| Суть | P5.1 только после GREEN-аудита P4; отдельная Sol/high-сессия |

```
$ task:plan-run 2026.07.18-AZGUARD-STABLE P5.1
```

### P4.8 → P4.7 → B6 — portability-ремедиация (последовательно)

Порядок жёсткий (research/04 §3): **P4.8** (миграция 000005, ПЕРВЫМ — MySQL-каскад
маскирует нижележащее) → **P4.7** (000002/000010 key-length+collation) → **B6** = P4.9→P4.10.
P4.8 уже `🟡 In progress`: сначала инвентаризировать и продолжить имеющийся dirty diff.
P4.8/P4.7 — `plan-run` manual на Terra/high; B6 — `plan-exec` на Terra/medium. Стенд должен быть поднят
(`PGSQL_PORT=25432`/`MYSQL_PORT=23306` — handoff).

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Codex | GPT-5.6 Terra |
| Effort | high (P4.8/P4.7) · medium (B6) |
| Context | same-session — item; cold-start reads: handoff.md → research/05 → plan.md D30–D34 → phases/P4.md P4.8 → research/04 → findings anchors |
| Суть | продолжить P4.8 dirty diff → review → P4.7 → review → P4.9/P4.10 → review |

```
$ task:plan-run 2026.07.18-AZGUARD-STABLE P4.8
```

### B6 — LIKE-фикс + green-proof (plan-exec серия, после P4.8/P4.7)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Codex | GPT-5.6 Terra |
| Effort | medium |
| Context | same-session — item: research/05 → plan.md → phases/P4.md P4.9/P4.10 → handoff.md |
| Суть | P4.9 (filament LIKE-escape) → P4.10 (green оба лейна + коммит CI-джоба + baseline→resolved) |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.9 P4.10
```

### B5 — релиз + закрытие (plan-exec серия, гейт владельца внутри)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Codex | GPT-5.6 Terra |
| Effort | medium |
| Context | same-session — item: research/05 → plan.md D22/D25/D26/D34 → phases/P5.md → findings/P5-rag-release-guard-2026-07-18.md |
| Суть | P5.2 (релиз: тег ТОЛЬКО после approve владельца) → P5.3 (миграция root/→docs, финальный handoff) |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P5.2 P5.3
```

## Review checkpoints

Один writer за раз. Reviewer только читает diff/логи и возвращает findings; fixes делает
writer-route. После двух неуспешных review/fix циклов — §10/`task:plan-design`, не третий
автоматический круг.

| После | Reviewer | Что проверяет | Дальше |
|:--|:--|:--|:--|
| P4.8 | GPT-5.6 Sol/high, read-only | raw SQL, morph-type fallback, MySQL FK/index down-order, тест-доказательства | findings → Terra/high fix; clean → закрыть item |
| P4.7 | GPT-5.6 Sol/high, read-only | key-byte calculation, collision semantics, driver guards, case-sensitive security invariant | findings → Terra/high fix; clean → закрыть item |
| B6 | GPT-5.6 Sol/high, read-only | LIKE portability + честность full green/CI wiring | findings → Terra/medium fix |
| P4.4 | GPT-5.6 Sol/high, read-only | race validity, false-green/false-negative risks, process isolation | findings → Terra/high fix или §10 |
| Фаза P4 | GPT-5.6 Sol/xhigh, новая сессия | adversarial `plan-audit P4` по всем deliverables/commits | только GREEN разрешает P5.1 |
| Фаза P5 | GPT-5.6 Sol/xhigh, новая сессия | release/docs/tag evidence и archive readiness | только GREEN разрешает archive |

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| P0.6 | бэклог ремедиации (волны/кластеры/отклонения) | ✅ пройден (D9) — P1/P2 разблокированы |
| P5.2 | approve перед `git push origin v0.3.0` (необратимая публикация тега) | завершение B5 (тег, GH Release, P5.3) |
