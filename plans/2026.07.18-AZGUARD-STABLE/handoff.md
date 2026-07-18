# HANDOFF — 2026-07-18 — after P3.2

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/high. P3.2 закрыт (🟢) — snapshot-гейт заморозки
`@api`-поверхности живёт в `tests/Unit/ApiBoundaryTest.php` + фикстур
`tests/Fixtures/api-surface.snapshot.php`. Следующий item — **P3.3**
(SemVer-политика 0.x + каталог ограничений + UPGRADING 0.2→0.3): Routing §3 —
`sonnet/high manual`, roadmap — solo. Несёт обязательный вход: F2 (Audit P2) +
Pending Work P3.1/P3.2.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписано §3 Routing/D28 (контракт-язык SemVer-политики, состав breaking предписан) |
| Context | continue (/clear) — ручной item |
| Суть | P3.3: root/semver-policy.md (снапшот-гейт как энфорсер + процедура D#-регенерации) + каталог ограничений (≥6, с адресами) + консолидированная глава 0.2→0.3 в docs/introduction/upgrading.md EN+RU |

```
/model sonnet
/effort high
/task:plan-run 2026.07.18-AZGUARD-STABLE P3.3
```

**Done:** P3.2 (Заморозка: snapshot-гейт поверхности) закрыт 🟢. Расширен
существующий `tests/Unit/ApiBoundaryTest.php` (третий тест, переиспользованы
`$classesIn`/`$hasTag`): reflection-энумератор всех `@api`-типов core (32 —
1:1 с реестром `root/api-surface.md`) снимает kind + публичные методы,
объявленные в самом типе, с нормализованной сигнатурой (static, имя, порядок
и ИМЕНА параметров — named-arguments-контракт, типы FQCN-строкой, by-ref/
variadic, дефолт как presence-маркер `= default`, тип возврата); engine-методы
и метод-уровня `@internal` исключены (внутренности не замораживаются). Сверх
reflection заморожен docblock-`@method`-состав фасада (14 строк до
`--- @internal`-маркера) — иначе cut-line P3.1 не был бы защищён (reflection
видит у фасада только `fake()`). Фикстур пишется детерминированным
генератором (ksort/sort, pint-стабильный формат, идемпотентность подтверждена
md5), сверка даёт читаемый lost/gained-diff, регенерация — ТОЛЬКО осознанно:
env `AZ_UPDATE_API_SNAPSHOT=1` / `composer test:api-snapshot:update`
(докблок теста и заголовок фикстура требуют D# + bump). Самопроверка: мутация
имени параметра `PermissionMatcher::matches()` → red с точным diff'ом, откат
→ green. Гейт бежит в существующем CI (tests/Unit, отдельный workflow не
нужен). Validation на `27d46b7`: api-snapshot 3 passed/218 · test:unit
287/790 · analyse 0 errors · lint:check passed · refactor:check 0 · полный
`composer test` 667/1775.

**Remaining:** P3.3 (SemVer-политика + UPGRADING, sonnet/high — несёт F2
pending + каталог-заметки P3.2) → Phase Handoff P3 (`/task:plan-close`) →
штатно P4 (тест-углубление, 7 items) → P5 (шаблон → релиз+тег → миграция
root/→docs) → post-plan `/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.18,
D1–D29, §4 P3=🟡 1/3) · phases/P3.md (P3.2 Completion Notes — полный дизайн
снапшота и границы заморозки) · tests/Fixtures/api-surface.snapshot.php
(закоммиченный SSOT замороженной поверхности) · root/api-surface.md
(человекочитаемый реестр, вход P3.3) · root/contracts/facade-cutline.md
(исполненная спека cut-line, D29) · roadmap.md.

**Open risks:**
- F2 (Audit P2) не исполнен — P3.3 обязан внести в UPGRADING оба пропущенных
  breaking (P2.4 `panel_check` арг-флип, P2.1 переезды публичных типов);
  Required Reads P3.3 указывает на это явно.
- `AzGuardFake` заморожен целиком, включая passthrough-методы менеджера без
  метод-`@internal` (`tryPermission`/`panelIdForPermission` и др.) — если это
  шум, снятие через D# + метод-теги на фейке, НЕ ослаблением гейта (P3.2
  Pending Work → каталог P3.3).
- Снапшот покрывает только core; filament/context вне конвенции `@api` —
  каталогизировать в P3.3 (P3.2 Scope Excluded).
- `Attributes/*` документированы, но без `@api`-тегов (root/api-surface.md
  §8) — вне снапшота, кандидат отдельного item'а/каталога P3.3.
- Регенерация бandled boost-скилла целиком после cut-line — кандидат
  (facade-cutline.md §5, P2.5/P3.1 Pending Work).
- `tests/Unit/Support/` (5 файлов) — имя каталога дрейфует от канона (P2.1
  Pending Work, не тронуто).
- Удаление legacy `WildcardPermissionMatcher` + флага — deprecate-цикл ПОСЛЕ
  0.3.0 (P2.9 Pending Work, кандидат known-limitations P3.3).
- MySQL-ветка миграции 000005 / миграция 000011 (expires_at) не гонялись на
  MySQL локально — верификация в P4.2/P4.7.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).
- `AzGuard::fake()` + глобальный `Event::fake()`: подавляет реальную доставку
  слушателей (doc-note в advanced/testing.md).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; докблок-`@see` на импорт,
  используемый только в PHPDoc, переписывается прозой (rector↔pint, F1 P3.1);
  дефолты параметров в снапшоте — presence-маркер `= default`, не значение
  (значение = поведение, вне сигнатурного контракта — D20).
- deferred: F2 (Audit P2) → P3.3; снапшот filament/context → каталог P3.3;
  `Attributes/*` тегирование → кандидат; boost-скилл целиком → кандидат;
  удаление legacy-matcher → post-0.3.0; roave/bc-check → отложен (D20);
  split/Packagist (D25); rename `tests/Unit/Support/` → отдельный item;
  `removeScopedRoleEverywhere()` → контракт (P2).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
