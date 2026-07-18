# HANDOFF — 2026-07-18 — after P1.1

**Next:** исполнить P1.2 (W1, 12 Major-находок: C-13, C-11, C-10, C-08, C-02, C-03, C-04,
C-05, B-04, B-01, A-05, D-06) — per-finding коммиты в утверждённом гейтом порядке
(security→cache→docs/DX), см. phases/P1.md P1.2. C-02/C-03 правят тот же файл
(`HasScopedRoles.php`), что уже закрытый P1.1 — сверяться с актуальным состоянием файла.

| Параметр | Значение |
|:--|:--|
| Model | sonnet — Routing §3 P1.2 (manual); текущая сессия уже sonnet — `/task:plan-run` продолжает без переключения |
| Thinking | high — Routing §3 P1.2 (12 Major, часть security-sensitive: морф/mass-assign/wildcard/union-only); текущая сессия уже high |
| Context | continue (/clear) — ручной item |
| Суть | Закрыть 12 Major-находок W1 по одной, per-finding коммиты (D11), затем полный сьют + lint/analyse + docs-parity |

```
/task:plan-run 2026.07.18-AZGUARD-STABLE P1.2
```

**Done:** P1.1 закрыт (🟠 Done with deviations). `HasScopedRoles::bootHasScopedRoles()`
(packages/core/src/Concerns/HasScopedRoles.php:50) — убран `app()->runningInConsole()` из
условия раннего выхода; ранний выход теперь ровно `if (! Auth::check()) { return; }`; строки
60/72-84 (панель-резолюция, аддитив D5) байт-в-байт не тронуты (D27). Новый тест
`tests/Feature/ScopedRolesConsoleQueueTest.php` (2 кейса: queue-контекст `Auth::login()` без
HTTP/панели фильтрует по scoped-роли; genuine-console без актора — no-op), зарегистрирован в
`tests/Pest.php` (манульный uses()-список). Item-коммит `eed9099` — несёт также правку
`tests/Pest.php` (регистрация нового теста, механически неизбежна: файла нет в директорийном
автобиндинге) → путь вне декларированных `Files` item'а → status-rule требует 🟠, не 🟢
(Known Deviations в phases/P1.md P1.1). Validation (на eed9099): `pest --filter=ScopedRoles`
20 passed/42 assertions; полный сьют `pest` 559 passed/1530 assertions (регрессий нет, 3
кейса `ScopedRoleQueryScopePanelIsolationTest` зелены без правок); `composer lint:check` —
pint passed; `composer analyse` — phpstan 0 errors; grep-гейты (`runningInConsole` пуст,
`resolveDefault` только в `hasScopedPermission`) — оба подтверждены.

**Remaining:** P1.2 (W1, 12 Major) → P1.3 (W2, 14 Minor/Nit) → P1.4 (adversarial review) →
P2 канон (10 items) → P3 заморозка → P4 тест-углубление → P5 (шаблон → релиз+тег → миграция
docs) → post-plan `/task:plan-close archive`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.6, D1–D27) ·
phases/P1.md (P1.1 закрыт 🟠; P1.2 — следующий) · roadmap.md ·
research/{00-user-intent,02-backlog,03-p2-canon}.md · findings/ (REGISTER + оси + recon +
RAG) · brief/{00-brief,01-refinements}.md · open-questions.md (Q3→D27).

**Open risks:**
- P1.2 C-02 re-baseline: `on_missing_panel=exception`-дефолт заменяет аддитив D5 → кейс
  `ScopedRoleQueryScopePanelIsolationTest` «A1 — still applies an explicit-panel scope when
  NO panel is currently set» переписывается под новый fail-closed-контракт — осознанная смена,
  не ослабление; исполнитель обязан переписать ассерт, не удалить проверку.
- P1.2/P1.3 правят `HasScopedRoles.php` поверх уже закрытого P1.1 — читать актуальное
  состояние файла (не исходное из findings-якорей).
- Полный сьют гоняется как `php -d memory_limit=1G vendor/bin/pest` (bare `composer test`
  ещё OOM — фикс D-06 внутри самой P1.2, bullet 12).
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен (roadmap B5);
  red `composer check` → эскалация §10, не тихая починка.
- Split/Packagist отложены (D25); P4/P5-инфра-items требуют внешней среды → честный skip-note
  при недоступности, не слепой зелёный.
- `plan-lint.py` прогнан по прямому пути (найден в swissknifeman/packages/task/scripts/,
  `${CLAUDE_PLUGIN_ROOT}` не задан в среде) — следующему исполнителю может понадобиться тот же
  обходной путь, если переменная не восстановлена.

**Workarounds/Deferred/Open questions:**
- workarounds: полный сьют P1.1/P1.2 через `php -d memory_limit=1G vendor/bin/pest` (до D-06);
  `plan-lint.py` вызывается по абсолютному пути (`${CLAUDE_PLUGIN_ROOT}` пуст в этой сессии).
- deferred: split/Packagist one-time setup (D25); адоптация roave/bc-check (D20); per-token DB
  resolver для parallel на реальных БД (P4.3 YAGNI); снапшот filament/context-пакетов (P3.2 —
  пока только core-поверхность).
- open_questions: Q1→D22, Q2→D23/D24, Q3(D10-б/P1.1)→D27, scope релиза→D25. Открытых нет.
