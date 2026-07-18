# HANDOFF — 2026-07-18 — after P2.5

**Next:** ЗАПУСК ВРУЧНУЮ: `fable/high` (manual, Routing построчный D28).
Следующий item — P2.9 (пере-оценка breaking: wildcard-флип на Hierarchical
+ verify F4/F40/F51). Порядок §10 канона: P2.4 ✓ → P2.6 ✓ → P2.5 ✓ → P2.9 →
P2.7/P2.8 (plan-exec, порядок свободен) → P2.10 последним. Модельная карта
остатка (D28): fable — P2.9/P3.2/P5.1; sonnet/high — P3.1/P3.3/P4.7;
sonnet/medium plan-exec — P2.7/P2.8/P2.10/P4.1–P4.6/P5.2/P5.3.

| Параметр | Значение |
|:--|:--|
| Model | fable |
| Thinking | high — breaking-семантика permission-matcher'а: дефолт грамматики меняет поведение всех потребителей `*`-паттернов; re-baseline тестов требует различать «legacy-намерение vs случайность» (Routing D28) |
| Context | continue (/clear) — ручной item |
| Суть | P2.9: дефолт matcher → Hierarchical (legacy opt-out на цикл), PermissionSet вне контейнера выровнять, verify F4/F40/F51, breaking-заметка upgrading.md |

```
/model fable
/effort high
/task:plan-run 2026.07.18-AZGUARD-STABLE P2.9
```

(Форма D18: модель/effort сессии должны соответствовать Routing — гейт plan-run сверит.)

**Done:** P2.5 закрыт 🟢 (item-коммит `2a395ef`, 1 file, +122):
`root/contracts/facade-cutline.md` — замороженная target-спека cut-line
фасада, вход P3.1/P3.2. Состав: таблица ровно 17 вердиктов C-B4
(`@method | вердикт | обоснование | SemVer-эффект`) + пост-recon поверхность
P2.6 (fake() + 3 assert* → остаются @api) + target state докблока (итог: 14
@api @method + fake(); @internal-секция grant/revoke/grants+isSuperAdmin;
строки tryPermission/panelIdForPermission удаляются) + заметки
P3-исполнителю + команды воспроизводимости счётчиков (все числа на
`9190e8d`). Ключевая находка сверки с кодом (предписана Open risks прошлого
handoff): premise C-B4 «0 внутренних потребителей» мёртвых резолверов
опровергнут — `tryPermission` ← `Permissions/PermissionName.php:31` (шов
всех grant-путей, вошёл 3e9adb1 ДО аудита вне фасадной формы grep'а),
`panelIdForPermission` ← `Concerns/HasScopedRoles.php:324` (P1-W1 ed64c93,
ПОСЛЕ аудита) → **D29** (уточняет D19): P3.1 удаляет только 2
@method-СТРОКИ фасада, методы interface/manager получают `@internal`.
Согласованность с P2.3 (shorthands @internal) отмечена в спеке явно.
Validation на `2a395ef`: `test -f` ✓ · 17 строк-вердиктов ✓ · отметка P2.3
✓ · сверх ТЗ `composer lint:check` passed + `composer analyse` 0 errors
(кода item не менял). Session: fable/high — соответствует Routing.

**Remaining:** P2.9 (fable/high) → P2.7/P2.8 (sonnet/medium plan-exec) →
P2.10 последним (sonnet/medium plan-exec) → P3 cut-line/заморозка (P3.1
sonnet/high — теперь по спеке P2.5 + D29) → P4 тест-углубление → P5 (шаблон
→ релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.12,
D1–D29) · phases/P2.md (P2.1–P2.4 🟠, P2.5/P2.6 🟢, ТЗ P2.7–P2.10) ·
**root/contracts/facade-cutline.md (замороженная спека cut-line — SSOT
P3.1)** · research/03-p2-canon.md (канон, §8 wildcard-флип, §10 порядок) ·
root/architecture.md (ADR структуры) · findings/ (REGISTER + оси) ·
roadmap.md · brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md/filament.md/http-access.md/
  testing.md рассинхронены с EN после P2.3/P2.4/P2.6 — паритет закрывает
  P2.10 (`docs-parity-gate` до тех пор красен по этим страницам).
- `docs/recipes/temp-access-via-grant.md:33` показывает позиционный
  `AzGuard::revoke` (@internal с P2.3) — P2.10 doc-sweep (Pending Work P2.5).
- Bundled boost-скилл (`packages/core/resources/boost/skills/.../SKILL.md:102`)
  упоминает `AzGuard::isSuperAdmin` — регенерация после cut-line P3.1
  (кандидат P3.1-свип/P2.10; назван в спеке §5).
- P2.9 флип — breaking для всех `*`-паттернов потребителей: upgrading.md
  заметка обязательна (ТЗ); R7 (голый `*` из кастомной MergeStrategy при
  wildcard.enabled=true) — закрыть там же (D18).
- tests/Unit/Support/ — имя каталога дрейфует от канона (Pending Work P2.1,
  кандидат P2.10).
- MySQL-ветка миграции 000005 локально НЕ исполнялась — верификация в
  P4.2/P4.7; миграция 000011 (expires_at) гонялась только на sqlite — та же
  верификация.
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).
- `AzGuard::fake()` + глобальный `Event::fake()`: подавляет реальную
  доставку слушателей → fake не увидит grant/revoke (assertChecked не
  страдает) — doc-note P2.10, если станет практической проблемой.

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; rmdir заблокирован хуком
  среды — пустые каталоги через python os.rmdir (не влияет на git-дерево).
- deferred: R9 upgrade-нота C-10 → P3.3 (D21); RoleResource Livewire-тест
  (P1.2 Pending Work); split/Packagist (D25); roave/bc-check (D20); снапшот
  filament/context (P3.2); `removeScopedRoleEverywhere()` → контракт (P2);
  rename tests/Unit/Support/ → P2.10; прямой unit-тест
  SimplePermissionDefinition::label() + generics `Contracts\HasRoles::roles()`
  → P2.10 (P2.2 Pending Work); RU-зеркала P2.3+P2.4+P2.6 + дог-фуд корня в
  context-CLI → P2.10 (P2.3/P2.4 Pending Work); `direct-grants.md`
  `::using()`-пример → P2.10; `temp-access-via-grant.md` позиционный revoke
  → P2.10 (P2.5 Pending Work); boost-скилл регенерация → P3.1/P2.10 (P2.5
  Pending Work); `Event::fake()`+`AzGuard::fake()` doc-note → P2.10.
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
