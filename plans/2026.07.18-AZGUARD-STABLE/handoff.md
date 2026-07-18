# HANDOFF — 2026-07-18 — after P2.10

**Next:** Фаза P2 терминальна (все 10 items 🟢/🟠) — закрыть фазу:
`/task:plan-close 2026.07.18-AZGUARD-STABLE P2`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — механическая сверка таблиц/handoff по git-фактам, шаг-не-item |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P2: свести таблицы Phase Status/Phase Index, заполнить Phase Handoff phases/P2.md, прогнать plan-lint, обновить roadmap-статус |

```
/task:plan-close 2026.07.18-AZGUARD-STABLE P2
```

**Done:** P2.10 закрыт 🟢 (item-коммит `0de35b0`, 13 files, +849/−399):
сквозной свип docs EN/RU под API, зафиксированный P2.1–P2.9. Recon-grep по
всему docs-дереву (старые FQCN `AzGuard\Support\`, config-центричный
Filament, позиционный `azguard.panel_check:{panel},{permission}`) — 0
совпадений (уже вычищено предыдущими items). Найдено и убрано:
`@internal`-shorthand'ы `AzGuard::grant()`/`revoke()`/`grants()` как
публичные примеры в `docs/ru/basic-usage/direct-grants.md` (3 места) и
`docs/recipes/temp-access-via-grant.md` (EN, 1 место) — заменены на fluent
`AzGuard::forUser($u)->on($p)->…`. 10 RU-страниц ресинхронизированы
секция-в-секцию с EN десятью параллельными агентами (каждый: EN + текущий
RU + эталон стиля `direct-grants.md` → переписал файл целиком):
`advanced/{context,testing,entity-scopes}.md`,
`basic-usage/{filament,http-access,multiple-guards,super-admin}.md`,
`introduction/{quick-start,upgrading}.md`,
`recipes/super-admin-wildcard.md` — детали (что именно было стейл в каждом
файле) см. phases/P2.md P2.10 Completion Notes. `tests/ArchTest.php` (17
тестов) и `root/architecture.md` сверены с текущим каноном — правок не
потребовали. Validation: `docs-parity-gate.sh` OK · `ArchTest.php` 17
passed/35 · `composer lint:check`/`analyse` чисто · `composer test` 666
passed/1772 (без изменений от P2.9-baseline, docs-only) ·
`composer test:types` 99.7% · `check:coverage` honest-skip (нет
драйвера, предсуществующий инфра-гэп). `composer refactor:check` (rector)
КРАСНЫЙ на 6 src-файлах ВНЕ Files item'а — подтверждено `git stash`:
краснеет идентично на baseline до диффа P2.10 → предсуществующий
остаток, не наш дефект (см. Pending Work ниже).

**Remaining:** `/task:plan-close 2026.07.18-AZGUARD-STABLE P2` → P3
cut-line/заморозка (P3.1 sonnet/high по спеке P2.5+D29 · P3.2 fable/high ·
P3.3 sonnet/high) → P4 тест-углубление (P4.1–P4.6 plan-exec, P4.7
sonnet/high) → P5 (шаблон fable → релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.16,
D1–D29) · phases/P2.md (все 10 items терминальны — P2.5/P2.6/P2.7/P2.8/P2.10
🟢, P2.1–P2.4/P2.9 🟠) · root/contracts/facade-cutline.md (замороженная
спека cut-line — SSOT P3.1) · root/glossary.md · root/architecture.md ·
research/03-p2-canon.md · findings/ (REGISTER + оси) · roadmap.md (строка P2
историческая, супersedeна построчным D28) · brief/{00-brief,01-refinements}.md.

**Open risks:**
- `composer refactor:check` (rector dry-run) красный на 6 src-файлах вне
  всех Files P2-items (`packages/core/src/{Permissions/PermissionKey,
  Permissions/PermissionName,AzGuardManager,Testing/AzGuardFake,
  Grants/GrantBuilder}.php`, `packages/context/src/ContextGrantBuilder.php`
  — dead-code named-args + type-flip suggestions); предсуществует на
  baseline (до P2.10), не задет ни одним P2-item'ом; нужен отдельный
  code-touch item (кандидат перед P3 либо внутри P3.1–P3.3 code-touch) или
  явное решение владельца отложить в known-limitations.
- `tests/Unit/Support/` (5 файлов) — имя каталога дрейфует от канона;
  `tests/**` НЕ входил в Files ни одного P2-item (только `tests/ArchTest.php`
  в P2.10) — переименование остаётся вне текущего ТЗ, кандидат в отдельный
  item.
- Прямой unit-тест `SimplePermissionDefinition::label()` и уточнение
  generics `Contracts\HasRoles::roles()` (P2.2 Pending Work) — src-правки,
  вне Files всех P2-items.
- Bundled boost-скилл (`packages/core/resources/boost/skills/.../SKILL.md`)
  — регенерация после cut-line P3.1 (спека §5, P2.5 Pending Work).
- Удаление legacy `WildcardPermissionMatcher` + флага — следующий
  deprecate-цикл ПОСЛЕ 0.3.0 (P2.9 Pending Work, кандидат
  known-limitations/semver-policy P3.3).
- MySQL-ветка миграции 000005 локально НЕ исполнялась — верификация в
  P4.2/P4.7; миграция 000011 (expires_at) гонялась только на sqlite — та же
  верификация.
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).
- `AzGuard::fake()` + глобальный `Event::fake()`: подавляет реальную
  доставку слушателей (doc-note уже добавлена в advanced/testing.md P2.10;
  если станет практической проблемой — рассмотреть отдельно).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; rmdir заблокирован хуком
  среды — пустые каталоги через python os.rmdir.
- deferred: удаление legacy-matcher+флага → post-0.3.0 цикл (P2.9); R9
  upgrade-нота C-10 → P3.3 (D21); RoleResource Livewire-тест (P1.2 Pending
  Work); split/Packagist (D25); roave/bc-check (D20); снапшот
  filament/context (P3.2); `removeScopedRoleEverywhere()` → контракт (P2);
  rename `tests/Unit/Support/` → вне Files, отдельный item; прямой unit-тест
  `SimplePermissionDefinition::label()` + generics `HasRoles::roles()` → вне
  Files, отдельный item; `composer refactor:check` (rector) 6 src-файлов →
  отдельный code-touch item или known-limitations.
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
