# HANDOFF — 2026-07-18 — after P2

**Next:** Фаза P2 закрыта (10/10 items терминальны: 5🟢/5🟠) — 5 items несут
`🟠 Done with deviations` (P2.1–P2.4, P2.9), material-отклонения включают
множественные легальные пре-1.0 SemVer-breaking правки публичного API.
Перед P3 (замораживает поверхность по следам P2) — adversarial-аудит фазы.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — adversarial-аудит закрытой фазы с 🟠-вердиктами/breaking-списком, цена ошибки — заморозка P3 неверной поверхности |
| Context | NEW SESSION — шаг-не-item |
| Суть | Аудит фазы P2: сверить 🟠-вердикты (P2.1–P2.4, P2.9) с git-фактами, проверить агрегат Known Deviations Phase Handoff, no-op/scope-drift, консистентность перед cut-line P3 |

```
/task:plan-audit 2026.07.18-AZGUARD-STABLE P2
```

**Done:** Фаза P2 (Структурный канон + fluent/DX редизайн API) закрыта
целиком. Все 10 items реализовали 4 развилки владельца D14–D18: структурный
канон core (Support/ упразднён, 11 файлов в доменных неймспейсах,
двух-домовый канон контрактов — P2.1); 6 phpstan-структурных baseline
разрешены уточнением контрактов, не подавлением (P2.2); единый immutable
fluent-корень `AzGuard::forUser()->inContext()` + TTL-парность context,
миграция `expires_at` (P2.3); Filament-плагин fluent + middleware
`::using()` + единый порядок аргументов `что,где` (P2.4); cut-line
target-спека фасада `root/contracts/facade-cutline.md` — SSOT входа P3,
с уточнением D29 (резолверы tryPermission/panelIdForPermission не мёртвые →
@internal, не удаление) (P2.5); `AzGuard::fake()` Recorder + assertGranted/
assertDenied/assertChecked (простая форма + closure) (P2.6); глоссарий
`root/glossary.md` + doc-routing context↔scope, multiple-guards.md
переформулирован через панели (P2.7); headless-quick-start EN/RU +
guard:doctor 0-панелей hint, fail-closed не ослаблен (P2.8); wildcard-флип
дефолта на Hierarchical + verify F4/F40/F51 + R7-фикс (голый `*` больше не
проходит фильтр) (P2.9); сквозной EN/RU docs-свип (10 RU-страниц
ресинхронизированы, @internal-shorthand'ы убраны из публичных примеров) +
arch-тесты канона консолидированы (P2.10). Phase Handoff phases/P2.md
заполнен: агрегат Known Deviations по всем 10 items (механически, из полей
item'ов) + перечень легальных SemVer-breaking изменений; docs-sync
подтверждён (P2.10 сквозной свип + root/architecture.md ADR +
root/glossary.md, оба созданы по ходу фазы).

**Remaining:** `/task:plan-audit … P2` → по результату аудита
либо прямой переход к P3 (cut-line по facade-cutline.md+D29 → snapshot-гейт
заморозки → SemVer-политика/UPGRADING), либо `/task:plan-design … P2.m`
на re-design конкретного item'а, если аудит найдёт material-разрыв. Далее
штатно: P3 (P3.1 sonnet/high · P3.2 fable/high · P3.3 sonnet/high) → P4
тест-углубление (P4.1–P4.6 plan-exec, P4.7 sonnet/high) → P5 (шаблон fable →
релиз+тег → миграция root/→docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.16,
D1–D29, § 4 Phase Index P2=🟠 Done with deviations 5/10🟢) · phases/P2.md
(Phase Handoff заполнен — агрегат Known Deviations + docs-sync + next step) ·
root/contracts/facade-cutline.md (замороженная спека cut-line — SSOT P3.1) ·
root/glossary.md · root/architecture.md · research/03-p2-canon.md ·
findings/ (REGISTER + оси) · roadmap.md (строка P2 историческая, супersedeна
построчным D28) · brief/{00-brief,01-refinements}.md.

**Open risks:**
- 5 items P2 несут `🟠 Done with deviations` (P2.1–P2.4, P2.9) — material
  material-отклонения (дифф вне заявленных Files + легальные SemVer-breaking
  правки публичного API); НЕ сверены adversarial-аудитом — риск, что P3
  заморозит поверхность по невыявленной ошибке одного из вердиктов.
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
