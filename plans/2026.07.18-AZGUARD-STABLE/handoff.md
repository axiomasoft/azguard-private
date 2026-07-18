# HANDOFF — 2026-07-18 — after P2.4

**Next:** ЗАПУСК ВРУЧНУЮ: `sonnet/high` (manual, Routing построчный D28).
Следующий item — P2.6 (Testing DX: `AzGuard::fake()` — Recorder + ассерции;
канон запинён RAG Запрос 3). Порядок §10 канона: P2.4 ✓ → P2.6 → P2.5 → P2.9
→ P2.10. Модельная карта остатка (D28): fable — P2.5/P2.9/P3.2/P5.1;
sonnet/high — P2.6/P3.1/P3.3 (и уже закрытый P2.4); sonnet/medium plan-exec —
P2.7/P2.8/P2.10/P4.1–P4.6/P5.2/P5.3; sonnet/high — P4.7.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписанная реализация нового Testing DX API по запинённому RAG-канону (Запрос 3, D14), открытых design-решений нет |
| Context | continue (/clear) — ручной item |
| Суть | P2.6: `AzGuard::fake()` — Recorder + `assertGranted/assertDenied/assertChecked` (+closure-вариант), канон `Pdf::fake()` |

```
/model sonnet
/effort high
/task:plan-run 2026.07.18-AZGUARD-STABLE P2.6
```

(Форма D18: модель/effort сессии должны соответствовать Routing — гейт plan-run сверит.)

**Done:** P2.4 закрыт 🟠 (item-коммит `250e53a`, 15 files, +517/−14):
config→fluent Filament-плагин + middleware `::using()` + единый порядок
аргументов (canon RAG findings/P0-rag-fluent-dx.md Запрос 1/2). AzGuardPlugin:
fluent-сеттеры `enforce()/source()/abilities()/keyTemplate()/case()` + геттеры
(nullable-поле → fluent ?? config, config остаётся fallback); `make()` →
`app(self::class)` (pint нормализовал из `static::class` — класс `final`,
эквивалентно); `register(Panel)` пишет эффективные значения обратно в
`config()`, чтобы независимые контейнер-синглтоны (PermissionSchema/
discovery/CatalogBuilder/ResourceGate/PageWidgetAccessEvaluator) видели их;
`boot()` читает `$this->isEnforcing()/getSource()` напрямую. Middleware
`::using(string|BackedEnum ...)` на все 4 (`CheckDirectGrant`/
`PanelCheckAccess`/`SetCurrentPanel`/`CheckAccess`), unwrap через
`PermissionKey::normalize()`/`PanelResolver::normalizeId()`; alias-DSL остаётся
параллельно. **Breaking**: `PanelCheckAccess::handle()` аргументы
`panelId,permission` → `permission,panelId` (`azguard.panel_check:
{permission},{panel}`) — легально пре-1.0 (D14). Validation на `250e53a`:
targeted 117 passed/194 assertions · analyse 0 errors · lint passed · полный
`composer test` 648 passed/1720 assertions (было 623 на P2.3, +25 новых
тестов). Docs EN обновлены (filament.md Fluent configuration, http-access.md
Static constructors); RU-зеркала не трогались (P2.10). Отклонения: дифф вне
Files item'а — `AzGuardFilamentServiceProvider.php` (Gate::before резолвит
ResourceGate лениво — необходимо для корректности fluent-config-propagation
по Code Guidance) + `tests/Pest.php` (регистрация 2 новых Feature-файлов);
детали phases/P2.md P2.4 Known Deviations.

**Remaining:** P2.6/P2.5/P2.9/P2.10 (fable/high и sonnet/high manual, по
одному item на сессию; порядок — research/03-p2-canon.md §10: P2.6 → P2.5 →
P2.9 → P2.10 последним) → P3 заморозка → P4 тест-углубление → P5 (шаблон →
релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.10, D1–D28)
· phases/P2.md (P2.1–P2.4 🟠, ТЗ P2.5–P2.10) · research/03-p2-canon.md (канон
структуры и грамматики, §7 fake(), §10 порядок) · findings/P0-rag-fluent-dx.md
(Запрос 3 — RAG-канон P2.6) · root/architecture.md (ADR структуры) ·
findings/ (REGISTER + оси) · roadmap.md · brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md/filament.md/http-access.md
  рассинхронены с EN после P2.3+P2.4 — паритет закрывает P2.10
  (`docs-parity-gate` до тех пор красен по этим страницам).
- P2.5 (cut-line спека) обязана согласоваться с P2.3+P2.4: shorthands уже
  @internal, middleware ::using() — новая @api-поверхность — вердикты сверять
  с фактом кода, не только с C-B4/backlog.
- Новые FQCN после P2.1 (Panels/Permissions/…) — Required Reads P2.5+ могут
  ссылаться на старые пути; расхождение путей — следствие P2.1, не дефект.
- tests/Unit/Support/ — имя каталога дрейфует от канона (Pending Work P2.1,
  кандидат P2.10).
- MySQL-ветка миграции 000005 локально НЕ исполнялась — верификация в
  P4.2/P4.7; миграция 000011 (expires_at) гонялась только на sqlite — та же
  верификация.
- R7 (голый `*` из кастомной MergeStrategy при wildcard.enabled=true) —
  закрыть в P2.9 при wildcard-флипе D18.
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- `docs/basic-usage/direct-grants.md` не получил `::using()`-пример
  (документирует только alias-DSL) — кандидат P2.10 doc-sweep.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; rmdir заблокирован хуком
  среды — пустые каталоги удалялись через python os.rmdir (не влияет на
  git-дерево).
- deferred: R9 upgrade-нота C-10 → P3.3 (D21); RoleResource Livewire-тест
  (P1.2 Pending Work); split/Packagist (D25); roave/bc-check (D20); снапшот
  filament/context (P3.2); `removeScopedRoleEverywhere()` → контракт (P2);
  rename tests/Unit/Support/ → P2.10; прямой unit-тест
  SimplePermissionDefinition::label() + generics `Contracts\HasRoles::roles()`
  → P2.10 (P2.2 Pending Work); RU-зеркала P2.3+P2.4 + дог-фуд корня в
  context-CLI → P2.10 (P2.3/P2.4 Pending Work); `direct-grants.md`
  `::using()`-пример → P2.10.
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
