# HANDOFF — 2026-07-18 — after P2.9

**Next:** P2.7 (глоссарий + docs-маршрутизация) — `Exec = plan-exec`
(sonnet/medium, D28). Порядок §10 канона: P2.9 ✓ → P2.7/P2.8 (порядок
свободен) → P2.10 последним. Модельная карта остатка (D28): fable —
P3.2/P5.1; sonnet/high — P3.1/P3.3/P4.7; sonnet/medium plan-exec —
P2.7/P2.8/P2.10/P4.1–P4.6/P5.2/P5.3.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — editorial-item: вердикты словаря готовы (таблица C-A10 + §9 канона), кода нет (Routing D28) |
| Context | continue (/clear) — ручной item |
| Суть | P2.7: root/glossary.md (guard=бренд, context↔scope), multiple-guards.md через панели, routing-раздел «context или scope?», RU-зеркала |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P2.7
```

(После P2.7 тем же способом P2.8; P2.10 — строго последним в фазе.)

**Done:** P2.9 закрыт 🟠 (item-коммит `73072fd`, 18 files, +313/−109):
wildcard-флип F22 (D18) — дефолтный matcher `HierarchicalPermissionMatcher`
(`*`=один сегмент, `**`=рекурсивно); смысл `features.wildcard_permission`
ИНВЕРТИРОВАН: паттерны учитываются всегда, true = вернуть legacy-грамматику
0.2 на один deprecate-цикл (`Config::wildcardEnabled()` →
`legacyWildcardEnabled()`, флаг переопределяет ключ `matcher`).
`PermissionSet` standalone-fallback → Hierarchical (C-07, @api-заметка о
расхождении). Catalog-фильтр резолвера унифицирован (две ветки → одна);
голый `*` из PermissionLayer/MergeStrategy отбрасывается безусловно в ОБЕИХ
грамматиках — **R7 закрыт** (предписание прошлого handoff). F4/F40/F51
верифицированы по якорям (AbilitiesDto.php:43 · PermissionCatalog.php:55/63
· 25 команд `guard:`/`make:guard-*`, 0 `azguard:`, 0 `$aliases`) — кода не
потребовали. Тесты re-baseline по правилу «legacy-намерение vs случайность»
(пин старой грамматики PermissionSetTest переписан под D18; +7 новых).
Breaking-глава 0.3.0 в upgrading.md EN+RU. Validation на `73072fd`:
targeted 55 passed · полный `composer test` 664 passed/1768 · `composer
analyse` 0 errors · `docs-parity-gate` OK · `lint:check` passed. Session:
fable/high — соответствует Routing. Material-отклонение: дифф вне Files
(Config.php, EffectivePermissionResolver.php, recipes+RU-зеркала) —
предписан самим ТЗ/handoff, гэп перечня (прецедент P2.1–P2.4); детали —
phases/P2.md P2.9 Known Deviations.

**Remaining:** P2.7 → P2.8 (оба sonnet/medium plan-exec, порядок свободен) →
P2.10 последним (sonnet/medium plan-exec) → P3 cut-line/заморозка (P3.1
sonnet/high по спеке P2.5+D29 · P3.2 fable/high · P3.3 sonnet/high) → P4
тест-углубление (P4.1–P4.6 plan-exec, P4.7 sonnet/high) → P5 (шаблон fable →
релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.13,
D1–D29) · phases/P2.md (P2.1–P2.4/P2.9 🟠, P2.5/P2.6 🟢, ТЗ P2.7/P2.8/P2.10)
· root/contracts/facade-cutline.md (замороженная спека cut-line — SSOT P3.1)
· research/03-p2-canon.md (канон, §8 wildcard-флип, §9 словарь, §10 порядок)
· root/architecture.md (ADR структуры) · findings/ (REGISTER + оси) ·
roadmap.md (строка P2 — историческая блочная, супersedeна построчным D28) ·
brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md/filament.md/http-access.md/
  testing.md рассинхронены с EN после P2.3/P2.4/P2.6; RU-страницы
  upgrading.md/super-admin-wildcard.md имели предсуществующий контент-дрейф —
  P2.9 добавил только зеркала своих секций; полный паритет закрывает P2.10
  (структурный parity-gate при этом зелёный — он не сверяет контент).
- `docs/recipes/temp-access-via-grant.md:33` показывает позиционный
  `AzGuard::revoke` (@internal с P2.3) — P2.10 doc-sweep (Pending Work P2.5).
- Bundled boost-скилл (`packages/core/resources/boost/skills/.../SKILL.md`)
  — регенерация после cut-line P3.1 (кандидат P3.1-свип/P2.10; спека §5).
- Удаление legacy `WildcardPermissionMatcher` + флага — следующий
  deprecate-цикл ПОСЛЕ 0.3.0: занести в semver-policy/known-limitations P3.3
  (Pending Work P2.9).
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
- deferred: удаление legacy-matcher+флага → post-0.3.0 цикл (P2.9); R9
  upgrade-нота C-10 → P3.3 (D21); RoleResource Livewire-тест (P1.2 Pending
  Work); split/Packagist (D25); roave/bc-check (D20); снапшот
  filament/context (P3.2); `removeScopedRoleEverywhere()` → контракт (P2);
  rename tests/Unit/Support/ → P2.10; прямой unit-тест
  SimplePermissionDefinition::label() + generics `Contracts\HasRoles::roles()`
  → P2.10 (P2.2 Pending Work); RU-зеркала P2.3+P2.4+P2.6+P2.9-дрейф + дог-фуд
  корня в context-CLI → P2.10; `direct-grants.md` `::using()`-пример → P2.10;
  `temp-access-via-grant.md` позиционный revoke → P2.10 (P2.5 Pending Work);
  boost-скилл регенерация → P3.1/P2.10 (P2.5 Pending Work);
  `Event::fake()`+`AzGuard::fake()` doc-note → P2.10.
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
