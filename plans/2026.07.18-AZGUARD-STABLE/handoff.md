# HANDOFF — 2026-07-18 — after P1

**Next:** ЗАПУСК ВРУЧНУЮ: `fable/high` (manual, Routing P2.1–P2.10). Первый item —
P2.1 (структурный канон + fluent/DX редизайн, SemVer-breaking, effort high+
MANDATORY). Команды plan-exec/plan-run нет — план запрещает автозапуск для
контракт-класса P2 (Execution Rules: Exec=manual). Промпт item'а — из
`phases/P2.md` P2.1 (детализирован до DoR, research/03-p2-canon.md §10).

| Параметр | Значение |
|:--|:--|
| Model | fable (opus-класс) |
| Thinking | high — MANDATORY для контракт-класса (SemVer-breaking редизайн) |
| Context | continue (/clear) — ручной item |
| Суть | P2.1: первый item структурного канона/fluent-DX редизайна публичных контрактов |

```
ЗАПУСК ВРУЧНУЮ: fable/high — /clear, затем выполнить P2.1 по ТЗ phases/P2.md
(plans/2026.07.18-AZGUARD-STABLE/phases/P2.md, item P2.1), Sources of truth —
research/03-p2-canon.md §10, план v0.3.8, D14–D18.
```

**Done:** Фаза P1 закрыта (`plan-close Pn`). Все 4 items терминальны: P1.1/P1.2/P1.3
🟠 Done with deviations, P1.4 🟢 Done. Status Board (plan.md §4) синхронизирован:
P1 → 4/4, 🟠 Done with deviations. Phase Handoff в `phases/P1.md` финализирован
(снят черновой маркер «ещё не закрыта»), Docs-sync проверен — не требуется (все
27 находок + 10 review-фиксов P1.4 — коррекции уже задокументированного поведения:
fail-closed изоляция scope, morph-канон, mass-assign, NULL-safe unique; `docs/` не
затронуты). Известные отклонения (агрегат из Known Deviations items, механически):
- P1.1: default-fallback панели упразднён владельцем (D27, supersedes D10-б) —
  process-отклонение, зафиксировано в переопределении D10 до закрытия item'а.
- P1.2: 12 находок закрыты, но премис-дефект бэклога «firstOrCreate второй
  аргумент = safe path» обнаружен и запинён тестом `f0055ae` (P1.4), формулировка
  в REGISTER не исправлена.
- P1.3: `HasScopedRoles::removeScopedRoleEverywhere()` вне контракта (allowlist
  D-03) — вынесено как кандидат в P2 contract review, не устранено здесь.
- P1.4: 3 находки (R7, R9, R12) приняты-как-риск с явным маршрутом (R7→P2/D18,
  R9→P3.3, R12→P2), а не устранены в фазе.
Ни одно из вышеперечисленного не устранено ПОЗЖЕ соседним коммитом внутри фазы —
маршруты остаются открытыми в P2/P3.3, актуальны на момент закрытия.
Item-коммит закрытия фазы — следующим шагом этого прогона (см. ниже).

Validation на финальном дереве P1 (`49238d9`, отчёт P1.4 `398985f`): `composer test`
— 610 passed / 1639 assertions; `composer lint:check` — pint passed; `composer
analyse` — phpstan 0 errors, baseline не менялся; `bash bin/docs-parity-gate.sh`
— OK. `git status --short` на момент plan-close — чисто, посторонних/грязных
файлов в дереве фазы не найдено.

**Remaining:** P2 канон (10 items, fable/high manual) → P3 заморозка → P4
тест-углубление → P5 (шаблон → релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.8, D1–D27,
§4 Status Board синхронизирован) · phases/P1.md (4/4 терминальны, Phase Handoff
финализирован) · phases/P2.md (DoR, 10 items) · findings/P1-review-2026-07-18.md
(16 находок, вердикты) · roadmap.md · research/{00-user-intent,02-backlog,
03-p2-canon}.md · findings/ (REGISTER + оси) · brief/{00-brief,01-refinements}.md.

**Open risks:**
- MySQL-ветка миграции 000005 (functional key parts + SUBSTRING 191,
  COALESCE-unique) локально НЕ исполнялась (SQLite-лейн) — обязательная
  верификация в P4.2/P4.7 (БД-матрица); упасть может только там, не тихо.
- R7: при `wildcard.enabled=true` голый `*` из кастомной MergeStrategy всё ещё
  проходит catalog-фильтр — закрыть в P2 при wildcard-флипе D18, не забыть.
- Premис-дефект бэклога «firstOrCreate второй аргумент = safe path» (P1.2 Known
  Deviations) теперь запинён тестом (`f0055ae`) — но формулировка в REGISTER не
  исправлена; при P2-опоре на REGISTER перепроверять сигнатуры.
- `HasScopedRoles::removeScopedRoleEverywhere()` вне контракта (allowlist D-03) +
  Policy-авторизация Filament RoleResource (side-note security-агента) — кандидаты
  P2 contract/Filament review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути
  (swissknifeman/packages/task/scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути (`${CLAUDE_PLUGIN_ROOT}` пуст).
- deferred: R9 upgrade-нота C-10 → P3.3 (D21); R12 reverse-parity сигнатуры → P2;
  RoleResource Livewire-тест (P1.2 Pending Work); split/Packagist (D25);
  roave/bc-check (D20); снапшот filament/context (P3.2);
  `removeScopedRoleEverywhere()` → контракт (P2).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
