# Roadmap исполнения — 2026.07.18-AZGUARD-STABLE

<!-- Собран при завершении детализации всех фаз (design pass 3, P5); finish делает сквозной
     reconcile и при необходимости обновляет карту. Roadmap не переопределяет Routing §3
     (модели/effort) — только группирует запуски. Живой документ: закрытие фазы /
     re-design обновляет таблицу (+строка в Update Log плана). -->

**Обновлён:** 2026-07-22 · **Соответствует plan.md:** v0.3.35 (P4 закрыта: 15/15
terminal, 11🟢/4🟠; далее независимый audit P4)

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
| P4.8 | — | ✅ закрыт | implementation/high → Terra/high | — | migration commits `1179b7c`/`91a67d7`, Sol/high review и повторная validation после P4.11 зелёные |
| P4.11 | solo | ✅ закрыт | implementation/medium → Terra/medium | — | named SuperAdminRole fixture, PG proof и Sol/high review |
| P4.7 | — | 🟠 закрыт | implementation/high → Terra/high | — | `4c4970f`: key-length+collation 000002/000010 и Sol/high APPROVE; MySQL RefreshDatabase red передан P4.10 |
| P4.9 | B6 | ✅ закрыт | implementation/medium → Terra/medium | — | LIKE-escape portability proof completed; B6 remains blocked before P4.10 |
| P4.12 | — | ✅ закрыт | implementation/high → Terra/high | — | D37 short table-aware MorphColumns index names; historical focused proof/review preserved |
| P4.13 | solo | ✅ закрыт | implementation/high → Terra/high | — | D38 permitted digest, three-driver proof, Sol/high APPROVE |
| P4.14 | solo | ✅ закрыт | implementation/medium → Terra/medium | — | D39 pgsql-only savepoint, direct SQLite/MySQL `QueryException`, full review |
| P4.15 | solo | 🟠 закрыт | implementation/medium → Terra/medium | — | D40 class-local Testbench reset; historical stdout deviation resolved by P4.10 fresh proof |
| P4.10 | B6 | ✅ закрыт | implementation/medium → Terra/medium | — | fresh-vendor union proof, CI DB matrix, EN/RU docs and baseline provenance; full review APPROVE |
| P4.3 | solo | ✅ закрыт | implementation/medium → Terra/medium | — | ParaTest, TEST_TOKEN isolation, 3× parallel proof; full review APPROVE |
| P4.4 | solo | ✅ закрыт (🟠) | implementation/medium → Terra/medium | — | `07cac2b`: race C-05/C-14, Sol/high APPROVE; raw epoch baseline 1 → 25 after 24 bumps |
| P4.5 | solo | ✅ закрыт | implementation/medium → Terra/medium | — | native Pest 98% ratchet, CI 100%, full review APPROVE |
| P4.6 | solo | 🟠 закрыт | implementation/medium → Terra/medium | — | baseline 29→10; existing UnitFilament suite retained |
| P4 audit | solo | next | frontier/xhigh → Sol/xhigh | — | read-only reconciliation P4 evidence before P5.1 |
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

### P4.8 → P4.11 → P4.7 → B6 — portability-ремедиация (последовательно)

Порядок жёсткий (research/04 §3, D36): **P4.8** migration уже закоммичен, но остаётся blocked
до **P4.11** — узкого proof/classification двух PG wildcard fixtures — затем сверка P4.8 →
**P4.7** (000002/000010 key-length+collation) → **B6** = P4.9→P4.10. P4.11 — `plan-exec`
на Terra/medium и Sol/high full review классификации; P4.8/P4.7 — `plan-run` manual на Terra/high;
B6 — `plan-exec` на Terra/medium. Стенд должен быть поднят
(`PGSQL_PORT=25432`/`MYSQL_PORT=23306` — handoff).

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Codex | GPT-5.6 Terra |
| Effort | medium (P4.11/B6) · high (P4.7) |
| Context | same-session — item; cold-start reads: handoff.md → research/05 → research/06 → findings/P4.8-wildcard-follow-up → plan.md D35–D36 → phases/P4.md P4.8/P4.11 |
| Суть | P4.11 PG fixture proof → Sol review → close P4.8 evidence → P4.7 → review → P4.9/P4.10 → review |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.11
```

### P4.13 → P4.14 → P4.15 → B6 — recover gates, затем green-proof

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Codex | GPT-5.6 Terra |
| Effort | high (P4.13) · medium (P4.14/P4.15/P4.10) |
| Context | same-session — item: research/05 → findings/P4.10-full-lane-blockers-2026-07-22.md → findings/P4.14-laravel-transaction-semantics-2026-07-22.md → findings/P4.10-ulid-refresh-state-2026-07-22.md → research/09-p4.14-driver-aware-savepoint.md → research/10-p4.15-ulid-refresh-isolation.md → plan.md D39–D40 → phases/P4.md P4.14/P4.15/P4.10 → handoff.md |
| Суть | P4.15 applies D40 class-local Testbench reset + Sol review → P4.10 clean full green/CI/baseline |

```
$ task:plan-exec 2026.07.18-AZGUARD-STABLE P4.15
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
| P4.11 | GPT-5.6 Sol/high, read-only | named fixture сохраняет persistent role path и test-only fix не маскирует runtime/P3 regression | findings → Terra/medium fix; clean → `plan-close P4.8` |
| P4.7 | GPT-5.6 Sol/high, read-only | key-byte calculation, collision semantics, driver guards, case-sensitive security invariant | findings → Terra/high fix; clean → закрыть item |
| P4.12 | GPT-5.6 Sol/high, read-only | deterministic short table-aware names, no fixture shortening, identical semantics across morph paths and three-driver proof | findings → Terra/high fix; clean → P4.10 |
| P4.13 | GPT-5.6 Sol/high, read-only | permitted deterministic digest, 48-character budget, no fixture shortening or architecture-rule bypass, three-driver proof | findings → Terra/high fix; clean → P4.14 |
| P4.14 | GPT-5.6 Sol/high, read-only | expected `QueryException` retained on all drivers, PostgreSQL savepoint truly recovers outer RefreshDatabase transaction, normal post-exception query passes, no global harness/migration change | findings → Terra/medium fix; clean → P4.10 |
| P4.15 | GPT-5.6 Sol/high, read-only | installed Testbench reset is class-local, fixed seed/full lanes show ULID config precedes migration, no migration/Pest global/API/P3 or dirty-file change | findings → Terra/medium fix; clean → P4.10 |
| B6 | GPT-5.6 Sol/high, read-only | LIKE portability + честность full green/CI wiring | findings → Terra/medium fix |
| P4.4 | GPT-5.6 Sol/high, read-only | race validity, false-green/false-negative risks, process isolation | findings → Terra/high fix или §10 |
| Фаза P4 | GPT-5.6 Sol/xhigh, новая сессия | adversarial `plan-audit P4` по всем deliverables/commits | только GREEN разрешает P5.1 |
| Фаза P5 | GPT-5.6 Sol/xhigh, новая сессия | release/docs/tag evidence и archive readiness | только GREEN разрешает archive |

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| P0.6 | бэклог ремедиации (волны/кластеры/отклонения) | ✅ пройден (D9) — P1/P2 разблокированы |
| P5.2 | approve перед `git push origin v0.3.0` (необратимая публикация тега) | завершение B5 (тег, GH Release, P5.3) |
