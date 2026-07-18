# HANDOFF — 2026-07-18 — after P1.2

**Next:** исполнить P1.3 (W2, 14 Minor/Nit-находок: A-01, A-02, B-07, B-09, B-10, B-11,
C-09, C-12, C-14, C-15, C-16, D-01, D-03, D-04) — per-finding коммиты в порядке §Scope
Included, см. phases/P1.md P1.3. Правит `HasScopedRoles.php`/`az-guard.php`/`tests/Pest.php`
поверх уже закрытых P1.1+P1.2 — сверяться с актуальным состоянием файлов, не с
findings-якорями.

| Параметр | Значение |
|:--|:--|
| Model | sonnet — Routing §3 P1.3 (manual, sonnet/medium); текущая сессия sonnet — без переключения |
| Thinking | medium предписано (Routing §3 P1.3); текущая сессия high — перебор безвреден, запись в Known Deviations |
| Context | continue (/clear) — ручной item |
| Суть | Закрыть 14 Minor/Nit-находок W2 по одной, per-finding коммиты (D11), затем полный сьют + lint/analyse + docs-parity |

```
/task:plan-run 2026.07.18-AZGUARD-STABLE P1.3
```

**Done:** P1.2 закрыт (🟠 Done with deviations). 12 находок волны W1 закрыты, 13
коммитов (12 per-finding в порядке гейта security→cache→docs/DX + 1 fix-up):
`81c3a5d` C-13 (ContextGrantBuilder::grant() бросает на wildcard-ключ + резолвер
не короткоциркуитит catalog-фильтр для layer-wildcard) · `c44fb98` C-11
(`class_name`/`scope_class` вне `$fillable` Role/ModelHasScope; ~85 тестовых
вызовов переведены на новый хелпер `createRoleWithClass()`) · `a53f45c` C-10
(`$user::class` → `$user->getMorphClass()` в GrantBuilder/DirectGrantSource/
ContextGrantBuilder/ContextPermissionLayer) · `0950f0e` C-08 (`ResourceGate`
возвращает `true|null`, не `(bool)` — union-only) · `4426206` C-02
(`az-guard.scope.on_missing_panel`, дефолт `exception`, ветки `empty`/`all`) ·
`fef343a` C-03 (лог stale `scope_class` once + чек `guard:doctor`) · `53fa4c4`
C-04 (`Config::assertCacheConfigValid()` в boot — бросает на infinite-TTL +
персистентный store) · `d514a86` C-05 (лог epoch-bump без `LockProvider`) ·
`ed64c93` B-04 (panelId → `string|BackedEnum`, role → `string|BackedEnum|Role`
на HasPermissions/HasDirectGrants/HasScopedRoles/HasRoles/
AzGuardManager::isSuperAdmin/AzGuardPlugin::forPanel + 4 контракта +
ResolvesRole + 2 ручные имплементации, которые иначе фатально ломались) ·
`7570017` D-06 (`composer test` → `php -d memory_limit=1G`) · `d000e56` B-01
(докс «Swapping core services», ресинк configuration.md с фактическим
az-guard.php) · `cd4311b` A-05 (докс Fakes, снят ложный «no fake/mock layer») ·
`7a8a10c` fix-up (см. ниже). Полные детали, включая найденные и исправленные
на закрытии волны пробелы C-11/C-10 (премис-дефект бэклога про
`firstOrCreate` — см. Known Deviations) — phases/P1.md P1.2 Completion
Notes/Known Deviations.

Validation на финальном дереве (7a8a10c): `composer test` (уже с
`-d memory_limit=1G`, D-06) — 584 passed / 1570 assertions; `composer
lint:check` — pint passed; `composer analyse` — phpstan 0 errors, baseline
не менялся; `bash bin/docs-parity-gate.sh` — OK; 3 grep-гейта item'а
подтверждены пустыми/корректными.

**Remaining:** P1.3 (W2, 14 Minor/Nit) → P1.4 (adversarial review) → P2 канон
(10 items) → P3 заморозка → P4 тест-углубление → P5 (шаблон → релиз+тег →
миграция docs) → post-plan `/task:plan-close archive`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.7, D1–D27) ·
phases/P1.md (P1.1/P1.2 закрыты 🟠; P1.3 — следующий) · roadmap.md ·
research/{00-user-intent,02-backlog,03-p2-canon}.md · findings/ (REGISTER + оси + recon +
RAG) · brief/{00-brief,01-refinements}.md · open-questions.md (Q3→D27).

**Open risks:**
- P1.3 правит `HasScopedRoles.php`/`az-guard.php`/`tests/Pest.php` поверх уже
  закрытых P1.1+P1.2 — читать актуальное состояние файлов, не исходное из
  findings-якорей (те же координаты строк из findings уже устарели после
  P1.1/P1.2).
- B-11 (P1.3) синхронизирует `@method`-типы фасада `AzGuard.php` с
  `AzGuardManagerInterface` — после B-04 (P1.2) интерфейс уже несёт
  `string|BackedEnum[|Role]`; убедиться, что фасадные докблоки отражают ИМЕННО
  пост-B-04 сигнатуры, а не пре-B-04 (`?string`).
- Премис-дефект бэклога, найденный в P1.2 (см. phases/P1.md P1.2 Known
  Deviations): формулировки находок про «firstOrCreate второй аргумент = safe
  path от fillable» — ФАКТИЧЕСКИ НЕВЕРНЫ (Eloquent's `firstOrCreate` создаёт
  через `fill()`, подчиняется `$fillable`). Если в P1.3/P2 встретится похожая
  формулировка про guarded-поле — перепроверить сигнатуру, не доверять
  premise'у бэклога буквально.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен
  (roadmap B5); red `composer check` → эскалация §10, не тихая починка.
- Split/Packagist отложены (D25); P4/P5-инфра-items требуют внешней среды →
  честный skip-note при недоступности, не слепой зелёный.
- `plan-lint.py` прогоняется по прямому пути (найден в
  swissknifeman/packages/task/scripts/, `${CLAUDE_PLUGIN_ROOT}` не задан в
  среде) — следующему исполнителю может понадобиться тот же обходной путь.

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` вызывается по абсолютному пути
  (`${CLAUDE_PLUGIN_ROOT}` пуст в этой сессии). `composer test` больше НЕ
  требует ручного `-d memory_limit=1G` (D-06 зашил его в сам скрипт).
- deferred: RoleResource (`CreateRole`/`EditRole`) не покрыт Livewire-тестом
  на сохранение guarded `class_name` через форму (P1.2 Pending Work — в
  проекте нет прецедента полного Livewire-тестирования Filament-страниц);
  split/Packagist one-time setup (D25); адоптация roave/bc-check (D20);
  per-token DB resolver для parallel на реальных БД (P4.3 YAGNI); снапшот
  filament/context-пакетов (P3.2 — пока только core-поверхность).
- open_questions: Q1→D22, Q2→D23/D24, Q3(D10-б/P1.1)→D27, scope релиза→D25.
  Открытых нет.
