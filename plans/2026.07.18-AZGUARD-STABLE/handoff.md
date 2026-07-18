# HANDOFF — 2026-07-18 — after P1.4

**Next:** закрыть фазу P1 (`plan-close`): все 4 items терминальны (P1.1/P1.2/P1.3 🟠,
P1.4 🟢) — сверка таблиц/борда, финализация Phase Handoff, lint. Шаг-не-item → новая
сессия (plan-close читает план с диска заново).

| Параметр | Значение |
|:--|:--|
| Model | sonnet — пин команды plan-close (sonnet/low) |
| Thinking | low — механика сверки/закрытия, не дизайн |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P1 по git-фактам: борд, Phase Handoff, lint; следом P2 (fable/high, manual) |

```
/task:plan-close 2026.07.18-AZGUARD-STABLE P1
```

**Done:** P1.4 закрыт (🟢 Done). Сквозной adversarial review диффа P1
(`bdf9416..HEAD`, 94 файла, 29 item-коммитов волн): субагенты
azguard-security-review (0 Blocker/Major, 10 векторов подтверждены) +
azguard-reviewer (15 находок) + 2 находки оркестратора; blade-review пропущен по ТЗ
(вьюхи не задеты). Итог — 16 находок с вердиктами в
`findings/P1-review-2026-07-18.md`; сняты 10 фикс-коммитами:
`5c555f1` R4/C-10 (morph-алиас в read-path query-scope — был fail-open изоляции под
enforceMorphMap) · `69d9e1a` R6/C-11 (атомарная запись scope_class одним INSERT) ·
`4bd209e` R2+R3/C-16 (NULL-safe COALESCE-unique + дедуп предсуществующих дублей в
миграции 000005, правлена in-place — не выпускалась) · `c681ee9` R1/C-14 (sync-джобы
больше не сбрасывают панель запроса — была регрессия) · `07ccbfe` R5/B-04
(hasRole принимает BackedEnum — был TypeError) · `cb8c819` R8/C-04 (валидация по
драйверу store, fail-closed для неизвестного) · `f0055ae` R0 (доказывающий
mass-assign тест C-11 — отсутствовал вопреки ТЗ P1.2) · `8d91611`/`0701d03` докблоки
C-15/C-09 · `49238d9` тест-гигиена. Принято-как-риск: R7 (wildcard-enabled ветка
C-13) → P2/D18; R9 (upgrade-нота C-10) → P3.3; R12 (reverse-parity сигнатуры) → P2.
Scope drift не найден (29 коммитов ↔ 27 находок). Item-коммит (отчёт): `398985f`.

Validation на финальном дереве кода (`49238d9`): `composer test` — 610 passed /
1639 assertions; `composer lint:check` — pint passed; `composer analyse` — phpstan
0 errors, baseline не менялся; `bash bin/docs-parity-gate.sh` — OK.

**Remaining:** plan-close P1 → P2 канон (10 items, fable/high manual) → P3 заморозка →
P4 тест-углубление → P5 (шаблон → релиз+тег → миграция docs) → post-plan
`/task:plan-close archive`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.8, D1–D27) ·
phases/P1.md (4/4 терминальны, Phase Handoff заполнен) ·
findings/P1-review-2026-07-18.md (16 находок, вердикты) · roadmap.md ·
research/{00-user-intent,02-backlog,03-p2-canon}.md · findings/ (REGISTER + оси) ·
brief/{00-brief,01-refinements}.md.

**Open risks:**
- MySQL-ветка миграции 000005 (functional key parts + SUBSTRING 191, COALESCE-unique)
  локально НЕ исполнялась (SQLite-лейн) — обязательная верификация в P4.2/P4.7
  (БД-матрица); упасть может только там, не тихо.
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
