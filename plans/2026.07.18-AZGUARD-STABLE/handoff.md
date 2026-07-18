# HANDOFF — 2026-07-18 — after P3.3

**Next:** `/task:plan-close 2026.07.18-AZGUARD-STABLE P3` — все 3 items фазы P3 терминальны
(P3.1 🟠, P3.2 🟢, P3.3 🟢): Phase Handoff synthesis (агрегат Known Deviations/SemVer-breaking
по трём items, docs-sync check, lint/plan-lint gate), затем — P4 (тест-углубление, 7 items).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — предписано SSOT-матрицей роли plan-close (bookkeeping/сверка, не design) |
| Context | NEW SESSION — шаг-не-item (plan-close читает план с диска заново) |
| Суть | Закрыть фазу P3: свести Known Deviations P3.1/P3.2/P3.3, подтвердить docs-sync, прогнать plan-lint.py, обновить Phase Index & Status Board |

```
/task:plan-close 2026.07.18-AZGUARD-STABLE P3
```

**Done:** P3.3 (SemVer-политика 0.x + каталог ограничений + UPGRADING 0.2→0.3) закрыт 🟢.
`root/semver-policy.md` (создан) — граница `@api`-поверхности (снапшот P3.2 как SSOT), 5
критериев breaking, deprecate-first дисциплина (legacy-wildcard прецедент), 6-шаговая легальная
процедура смены поверхности (регенерация фикстура строго с D#+bump в том же коммите),
roave/bc-check явно отложен (D20) до границы тега 1.0. `root/known-limitations.md` (создан,
отдельным файлом — под архивную карту D26) — 12 честных ограничений с адресом каждого (→P4.4
×2 хвоста race/Octane · opt-out-цикл legacy-wildcard · doc-only headless · unscheduled snapshot
core-only/`Attributes/*`/`AzGuardFake`-passthrough/`removeScopedRoleEverywhere()`/`tests/Unit/
Support/` · новый пункт — `Event::fake()` подавляет `AzGuard::fake()`-рекордер · →P4.2/P4.7
MySQL-ветки миграций · CI-side coverage-driver skip); проверено и НЕ внесено ложное ограничение
(публичный `InvalidCatalogException` — уже удалён ДО этого плана, REMAINDER_REPORT `bfc6813`).
`docs/introduction/upgrading.md` + RU-зеркало реструктурированы: новая мастер-секция `## 0.2 →
0.3` с 8 grep-верифицированными подразделами (query-scope fail-closed · единая fluent
grant-грамматика `expiresAt()`→`until()` · facade cut-line · Filament fluent+`::using()`+
`panel_check`-арг-флип · 11-строчная таблица неймспейс-переездов · контракт-сигнатуры
Authorizer/PermissionDefinition · wildcard-флип (перенесён, контент сохранён) · новые
config-ключи/2 миграции с командами публикации); старая секция переименована в `## 0.1 → 0.2 —
earlier API cleanup (historical)` с поясняющим blockquote. **F2 (Audit P2) закрыт**: оба
пропущенных breaking (panel_check-флип, PermissionKey/PanelProvider-переезды) внесены в
UPGRADING. Item-коммит `b7c39a5`: 4 files, +532/−10 (`git show --stat b7c39a5`). Validation:
`test -f root/semver-policy.md` ✓ · `grep -c '^| [0-9]' root/known-limitations.md` == 12 (≥6) ·
`bash bin/docs-parity-gate.sh` OK · `composer lint:check` passed · `composer analyse` 0 errors
(doc-only diff, страховка).

**Remaining:** Phase Handoff P3 (`/task:plan-close`) → P4 (тест-углубление: docker-стенд ·
БД-лейн · paratest · race-тесты C-05/C-14 · mutation-ratchet · чистка · collation MySQL, 7
items) → P5 (шаблонизация дорожки → релиз v0.3.0+тег → миграция root/→docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.19, D1–D29, §4 P3=🟠 2/3,
все items терминальны) · phases/P3.md (P3.1/P3.2/P3.3 Completion Notes) · root/semver-policy.md
(SSOT SemVer-политики) · root/known-limitations.md (SSOT каталога ограничений) ·
docs/introduction/upgrading.md + RU (консолидированная 0.2→0.3-глава) · root/api-surface.md
(P3.1, вход снапшота/политики) · root/contracts/facade-cutline.md (P2.5, D29) · roadmap.md.

**Open risks:**
- Фаза P3 не закрыта командой `plan-close` — Phase Handoff `phases/P3.md` всё ещё «—»,
  агрегат Known Deviations трёх items (P3.1 material из-за facade-cutline vs буквы ТЗ, P3.2
  design-надстройка @method-докблока, P3.3 —) не сведён.
- `Attributes/*` документированы, но без `@api`-тегов (root/api-surface.md §8, каталогизировано
  в known-limitations.md #6) — вне снапшота, кандидат отдельного item'а/каталога, не
  запланирован ни в одной фазе явно.
- `AzGuardFake` заморожен целиком, включая passthrough-методы менеджера без метод-`@internal`
  (known-limitations.md #7) — снятие через D# + метод-теги на фейке, НЕ ослаблением гейта.
- Снапшот покрывает только core; filament/context вне конвенции `@api` (known-limitations.md
  #5) — unscheduled follow-up, не в Routing ни одной будущей фазы.
- Новый каveat (найден при P3.3): `AzGuard::fake()` + глобальный `Event::fake()` в одном тесте
  подавляет реальную доставку слушателей fake()-рекордера — известное ограничение
  (known-limitations.md #8), НЕ ещё задокументировано doc-note в `docs/advanced/testing.md`
  (doc-only follow-up, не в текущем Files ни одного item'а).
- `HasScopedRoles::removeScopedRoleEverywhere()` публичен на трейте, но отсутствует в контракте
  `Contracts\HasScopedRoles` (known-limitations.md #9) — добавление было бы breaking
  interface-addition, не запланировано ни в одной фазе.
- `tests/Unit/Support/` (5 файлов) — имя каталога дрейфует от канона (P2.1 Pending Work,
  known-limitations.md #11).
- Удаление legacy `WildcardPermissionMatcher` + флага — deprecate-цикл ПОСЛЕ 0.3.0
  (known-limitations.md #3).
- MySQL-ветка миграции 000005 / миграция 000011 (expires_at) не гонялись на MySQL локально —
  верификация в P4.2/P4.7 (known-limitations.md #10).
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/scripts/,
  `${CLAUDE_PLUGIN_ROOT}` пуст в среде).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути.
- deferred: `Attributes/*` тегирование → кандидат; boost-скилл целиком → кандидат; удаление
  legacy-matcher → post-0.3.0 (opt-out-цикл); roave/bc-check → отложен до границы тега 1.0
  (D20, подтверждено в semver-policy.md §4); split/Packagist (D25); rename `tests/Unit/
  Support/` → отдельный item; `removeScopedRoleEverywhere()` → контракт-ревью (post-0.3.0);
  `Event::fake()`-каveat → doc-note в testing.md (новый deferred, найден P3.3); filament/context
  `@api`-конвенция + сателлитные снапшоты → unscheduled follow-up (новый deferred, найден P3.3).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
