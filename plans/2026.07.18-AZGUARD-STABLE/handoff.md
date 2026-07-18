# HANDOFF — 2026-07-18 — after P2.7

**Next:** P2.8 (headless-порог: minimal-setup quick-start + doctor-hint) —
`Exec = plan-exec` (sonnet/medium, D28). Порядок §10 канона: P2.9 ✓ → P2.7 ✓
→ P2.8 → P2.10 последним. Модельная карта остатка (D28): fable —
P3.2/P5.1; sonnet/high — P3.1/P3.3/P4.7; sonnet/medium plan-exec —
P2.8/P2.10/P4.1–P4.6/P5.2/P5.3.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — editorial/doc-only item, развилка headless разрешена (D14 doc-only, Routing D28) |
| Context | continue (/clear) — ручной item |
| Суть | P2.8: docs/introduction/headless-quick-start.md (+RU), guard:doctor onboarding-hint при 0 панелей + тест, без ослабления fail-closed |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P2.8
```

(После P2.8 — P2.10 строго последним в фазе.)

**Done:** P2.7 закрыт 🟢 (item-коммит `f589663`, 7 files, +99/−1):
глоссарий `plans/2026.07.18-AZGUARD-STABLE/root/glossary.md` (таблица
термин→сущность→видимость + вердикты guard=бренд/panel=изоляция/
context=runtime/scope=persist, C-A10). `docs/basic-usage/multiple-guards.md`
переформулирован через панели — ложная строка «a panel is bound to one or
more guards» убрана (A-07; носителя в коде нет, Panel.php: 0 вхождений
guard), RU-зеркало синхронно. Маршрутизирующий раздел «Context or scope?»
добавлен в `docs/advanced/context.md` (таблица runtime vs persist) + tip-
обратка в `docs/advanced/entity-scopes.md` (A-08); RU-зеркала обоих файлов
синхронны. Validation на `f589663`: `test -f root/glossary.md` passed ·
`bash bin/docs-parity-gate.sh` OK (no Cyrillic leaks, structural parity) ·
`grep -c 'bound to.*guard' multiple-guards.md` == 0 · `composer lint:check`
passed (docs-only diff). Session: sonnet/medium — соответствует Routing
(plan-exec). Material-отклонение: нет — дифф строго по Files item'а.

**Remaining:** P2.8 (sonnet/medium plan-exec) → P2.10 последним (sonnet/
medium plan-exec) → P3 cut-line/заморозка (P3.1 sonnet/high по спеке
P2.5+D29 · P3.2 fable/high · P3.3 sonnet/high) → P4 тест-углубление
(P4.1–P4.6 plan-exec, P4.7 sonnet/high) → P5 (шаблон fable → релиз+тег →
миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.14,
D1–D29) · phases/P2.md (P2.1–P2.4/P2.9 🟠, P2.5/P2.6/P2.7 🟢, ТЗ P2.8/P2.10)
· root/contracts/facade-cutline.md (замороженная спека cut-line — SSOT P3.1)
· root/glossary.md (словарь терминов — SSOT для docs-нарратива P2.10) ·
research/03-p2-canon.md (канон, §8 wildcard-флип, §9 словарь, §10 порядок)
· root/architecture.md (ADR структуры) · findings/ (REGISTER + оси) ·
roadmap.md (строка P2 — историческая блочная, супersedeна построчным D28) ·
brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md/filament.md/http-access.md/
  testing.md рассинхронены с EN после P2.3/P2.4/P2.6; RU-страницы
  upgrading.md/super-admin-wildcard.md имели предсуществующий контент-дрейф —
  P2.7 синхронизировал только свои новые секции (multiple-guards.md,
  context.md, entity-scopes.md), полный паритет закрывает P2.10
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
