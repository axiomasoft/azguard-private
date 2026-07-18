# HANDOFF — 2026-07-18 — after P3

**Next:** `/task:plan-exec 2026.07.18-AZGUARD-STABLE P4.1` — фаза P3 закрыта (2/3 🟢, 1/3
🟠 — все терминальны); P4 (тест-углубление, 7 items) уже детализирована до DoR (design
pass 3, Update Log v0.3.3) — Routing указывает P4.1 → sonnet/medium, `Exec = plan-exec`
(docker-стенд, инфра без прикладной логики, открытых design-решений нет).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing P4.1 (docker-стенд, механика без открытых решений) |
| Context | continue (/clear) — ручной item |
| Суть | Поднять docker-compose стенд (PG16/MySQL8/Redis7, SQLite дефолт) для БД-матрицы P4 |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P4.1
```

**Done:** Фаза P3 (Release-готовность: cut-line, заморозка поверхности, SemVer-политика)
закрыта — 3/3 items терминальны (P3.1 🟠, P3.2 🟢, P3.3 🟢).

- **P3.1** 🟠 Done with deviations — cut-line фасада по замороженной спеке P2.5/D29:
  `tryPermission`/`panelIdForPermission`/`isSuperAdmin` де-публикованы (`@internal`, НЕ
  удалены — оба метода несут внутренние швы: `Permissions/PermissionName.php:31`,
  `Concerns/HasScopedRoles.php:324`), `root/api-surface.md` создан (SSOT, 32 `@api`-типа
  core). Follow-up находки Audit P2 F1/F3/F4/F6 свёрнуты внутри item'а.
- **P3.2** 🟢 Done — snapshot-гейт заморозки: расширенный `ApiBoundaryTest.php` + фикстур
  `tests/Fixtures/api-surface.snapshot.php` (32 типа, сигнатуры+имена параметров),
  регенерация под `AZ_UPDATE_API_SNAPSHOT=1`/`composer test:api-snapshot:update`,
  самопроверка «мутация→red» подтверждена и откачена.
- **P3.3** 🟢 Done — `root/semver-policy.md` (5 критериев breaking, deprecate-first,
  6-шаговая процедура регенерации под D#) + `root/known-limitations.md` (12 пунктов с
  адресом) + консолидированная `## 0.2 → 0.3` в `docs/introduction/upgrading.md`+RU (8
  grep-верифицированных подразделов). F2 (Audit P2, UPGRADING-хвосты `panel_check`-флип +
  P2.1-переезды) закрыт.

**Known Deviations (агрегат по items, механически):**
- P3.1: (1) material — item-коммит несёт дифф вне литерального Files (F1 `composer
  refactor` авто-фикс на 3 файлах + bundled boost-скилл, следствие де-публикации #11
  facade-cutline.md), предписан follow-up-скоупом аудита, не scope creep. (2)
  SemVer-breaking: НЕТ нового — де-публикация (`@internal`), не removal; runtime не
  меняется. (3) process — буквальный текст Scope Included/Files item'а расходится с
  фактическим исполнением (методы сохранены, не удалены) — разрешено ЗАРАНЕЕ D29.
- P3.2: (1) process — pint авто-фикс стиля нового теста, штатная механика. (2)
  design-надстройка в рамках Intent — docblock-`@method`-состав фасада добавлен в
  снапшот сверх reflection-энумератора (Scope Included предписывал только его),
  необходим, иначе cut-line P3.1 не защищён от дрейфа. (3) минор — `kind` типа включён
  в entry (смена рода `@api`-типа тоже breaking), спека перечисляла только сигнатуры.
- P3.3: — (отклонений нет).

**Remaining:** P4 (тест-углубление: docker-стенд · БД-лейн · paratest · race-тесты
C-05/C-14 · mutation-ratchet · чистка · collation MySQL, 7 items) → P5 (шаблонизация
дорожки → релиз v0.3.0+тег → миграция root/→docs) → post-plan `/task:plan-close archive
2026.07.18-AZGUARD-STABLE`.

**Docs-sync:** обновлено — `docs/introduction/upgrading.md`+RU (консолидированная
0.2→0.3-глава), `root/semver-policy.md`, `root/known-limitations.md`, `root/api-surface.md`
(материал проекта; судьба docs при архивации плана — D26).

**Lint:** `plan-lint.py plans/2026.07.18-AZGUARD-STABLE --baseline HEAD` → 0 ERROR / 0 WARN
(после исправления §4 «Items 🟢/всего» P3 3/3→2/3 на этом закрытии) — новых от закрытия: 0.

**Sources of truth:** plan.md (v0.3.19, D1–D29, §4 P3=🟠 2/3 терминальна) · phases/P3.md
(P3.1/P3.2/P3.3 Completion Notes + Phase Handoff) · root/semver-policy.md ·
root/known-limitations.md · docs/introduction/upgrading.md+RU · root/api-surface.md ·
root/contracts/facade-cutline.md (P2.5/D29) · roadmap.md.

**Open risks:** нет новых сверх уже каталогизированных в `root/known-limitations.md` (12
пунктов, все с адресом → P4/doc-only/opt-out-цикл): `Attributes/*` без `@api`-тегов (#6) ·
`AzGuardFake` passthrough без метод-`@internal` (#7) · снапшот core-only, filament/context
вне конвенции (#5) · `AzGuard::fake()`+`Event::fake()` каveat, doc-note не написан (#8) ·
`removeScopedRoleEverywhere()` вне контракта (#9) · `tests/Unit/Support/` имя-дрейф (#11) ·
legacy `WildcardPermissionMatcher` deprecate-цикл после 0.3.0 (#3) · MySQL-ветки миграций не
гонялись локально, верификация в P4.2/P4.7 (#10) · P5.2 push тега — гейт владельца
обязателен (не в этой фазе) · `plan-lint.py` вызывается по абсолютному пути
(swissknifeman/packages/task/scripts/).

**Workarounds/Deferred/Open questions:** без изменений от P3.3 — см. `root/known-limitations.md`
как SSOT (заменяет прежний список deferred в handoff, D26: root/ — судьба docs). open_questions:
Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
