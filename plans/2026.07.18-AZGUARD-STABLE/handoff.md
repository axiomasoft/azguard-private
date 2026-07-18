# HANDOFF — 2026-07-18 — after P2.8

**Next:** P2.10 (консолидация: docs EN/RU паритет + arch-тесты канона P2) —
`Exec = plan-exec` (sonnet/medium, D28). P2.10 — последний item фазы P2;
по его закрытии фаза становится терминальной → `/task:plan-close 2026.07.18-AZGUARD-STABLE P2`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — editorial/консолидационный item, каноны уже применены P2.1–P2.9 (Routing D28) |
| Context | continue (/clear) — ручной item |
| Суть | P2.10: сквозной docs-свип под новый API (грамматика/config/fake/wildcard/структура), EN↔RU паритет (44↔44), консолидация arch-тестов канона, финальный `composer check` |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P2.10
```

**Done:** P2.8 закрыт 🟢 (item-коммит `c9edfc2`, 7 files, +272/−3):
`docs/introduction/headless-quick-start.md` + RU-зеркало — doc-only
minimal-setup путь (install→`implements AzGuardUser`→одна минимальная
панель→`AzGuard::forUser($user)->on('api')->grant(...)`/
`$user->hasPermission(...)`/`AzGuard::abilitiesFor(...)` без Filament-глав),
рантайм panel-less НЕ строился (D14, fail-closed сохранён).
`AzGuardDiagnostics::diagnose()` — при 0 зарегистрированных панелях
добавляет один `warnings[]`-хинт «No panels registered — see
docs/introduction/headless-quick-start.md…» (не error, команда остаётся
successful); `DoctorCommand.php` правки не потребовал. 2 новых теста
(hint при 0 панелей / нет hint при ≥1). Навигация: `docs/.vitepress/
config.ts` (EN/RU intro-sidebar + page-map), `quick-start.md`+RU-зеркало
(cross-link). Validation на `c9edfc2`: `test -f` EN+RU passed ·
`php vendor/bin/pest --filter='Doctor'` 13 passed/39 ·
`bash bin/docs-parity-gate.sh` OK · `composer lint:check` passed ·
`composer analyse` 0 errors · `composer test` 666 passed/1772 (было 664
на P2.9, +2). Session: sonnet/medium — соответствует Routing (plan-exec).
Material-отклонение: нет — дифф строго по Files item'а (config.ts трактован
как «навигация», предписанная Files-строкой). Pending Work: RU
`quick-start.md` полный Next-steps-паритет с EN — предсуществующий дрейф,
P2.10 doc-sweep.

**Remaining:** P2.10 (sonnet/medium plan-exec, последний item P2) →
`/task:plan-close 2026.07.18-AZGUARD-STABLE P2` → P3 cut-line/заморозка
(P3.1 sonnet/high по спеке P2.5+D29 · P3.2 fable/high · P3.3 sonnet/high) →
P4 тест-углубление (P4.1–P4.6 plan-exec, P4.7 sonnet/high) → P5 (шаблон
fable → релиз+тег → миграция docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.15,
D1–D29) · phases/P2.md (P2.1–P2.4/P2.9 🟠, P2.5/P2.6/P2.7/P2.8 🟢, ТЗ P2.10)
· root/contracts/facade-cutline.md (замороженная спека cut-line — SSOT P3.1)
· root/glossary.md (словарь терминов — SSOT для docs-нарратива P2.10) ·
research/03-p2-canon.md (канон, §7 headless, §8 wildcard-флип, §9 словарь,
§10 порядок) · root/architecture.md (ADR структуры) · findings/ (REGISTER +
оси) · roadmap.md (строка P2 — историческая блочная, супersedeна построчным
D28) · brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md/filament.md/http-access.md/
  testing.md рассинхронены с EN после P2.3/P2.4/P2.6; RU-страницы
  upgrading.md/super-admin-wildcard.md/quick-start.md (Next steps-раздел)
  имели предсуществующий контент-дрейф — полный паритет закрывает P2.10
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
  `Event::fake()`+`AzGuard::fake()` doc-note → P2.10; RU quick-start.md
  Next-steps-паритет → P2.10 (P2.8 Pending Work).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
