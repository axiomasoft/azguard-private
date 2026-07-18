# HANDOFF — 2026-07-18 — after P2.2

**Next:** ЗАПУСК ВРУЧНУЮ: `fable/high` (manual, Routing P2.1–P2.10; roadmap — каждый
item отдельной fable/high-сессией). Следующий item — P2.3 (единая grant-грамматика:
immutable fluent-корень core↔context + TTL-парность, D16). Порядок §10 канона:
P2.2 ✓ → P2.3 → P2.4/P2.6…/P2.5 → P2.9 → P2.10.

| Параметр | Значение |
|:--|:--|
| Model | fable |
| Thinking | high — контракт-класс, редизайн публичной grant-грамматики (SemVer-breaking легален, D14/D16) |
| Context | continue (/clear) — ручной item |
| Суть | P2.3: единый immutable fluent-корень грантов core↔context + TTL-парность context (миграция expires_at) |

```
/task:plan-run 2026.07.18-AZGUARD-STABLE P2.3
```

(Если сессия не fable/high — сначала `/model fable` + `/effort high`, D18.)

**Done:** P2.2 закрыт 🟠 (item-коммит `0724a24`, 13 files, +90/−67): все 6 структурных
phpstan-baseline-записей (D-09) разрешены уточнением контрактов по карте
research/03-p2-canon.md §2 и удалены; analyse 0 errors без них. Ключевое:
`Authorizer::check` → `Authorizable&Authenticatable` (guard в Gate::before);
`PermissionDefinition::label()` добавлен в @api-контракт + реализации — baseline
маскировал реальный runtime-баг (Filament звал несуществующий метод);
`ClassRoleGrantSource` — Model-гейт + типизированный доступ (trait-direct юзеры
сохранены); `RolePermissionsRelationManager` — narrowing до Role, попутно удалён
scoped-ignore из phpstan.neon. Два SemVer-breaking зафиксированы (легально, D14).
Validation на 0724a24: baseline-grep == 0 · analyse 0 errors · unit 270 passed ·
lint passed · полный `composer test` 611 passed / 1641 assertions.
Отклонения: дифф вне перечня Files (phpstan.neon — форсирован
reportUnmatchedIgnoredErrors; 3 тест-файла — пины изменённых контрактов) — гэп
перечня, детали phases/P2.md P2.2 Known Deviations.

**Remaining:** P2.3–P2.10 (fable/high manual, по одному item на сессию; порядок —
research/03-p2-canon.md §10: P2.3 → P2.4/P2.6…/P2.5 → P2.9 → P2.10 последним) →
P3 заморозка → P4 тест-углубление → P5 (шаблон → релиз+тег → миграция docs) →
post-plan `/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.8, D1–D27) ·
phases/P2.md (P2.1 🟠, P2.2 🟠, ТЗ P2.3–P2.10) · research/03-p2-canon.md (канон
структуры и грамматики, §3 grant-корень, §10 порядок) · root/architecture.md (ADR
структуры — новые FQCN) · findings/ (REGISTER + оси) · roadmap.md ·
brief/{00-brief,01-refinements}.md.

**Open risks:**
- Новые FQCN после P2.1 (Panels/Permissions/Configuration/Runtime/…) — Required
  Reads P2.3+ могут ссылаться на старые пути; проверяй по факту дерева,
  расхождение путей — следствие P2.1, не дефект.
- P2.3 строит грамматику ПОВЕРХ изменённых P2.2 контрактов (Authorizer
  intersection-тип, label() в PermissionDefinition) — при конфликте сигнатур
  сверяться с P2.2 Completion Notes, не откатывать.
- tests/Unit/Support/ — имя каталога дрейфует от канона (Pending Work P2.1,
  кандидат P2.10).
- MySQL-ветка миграции 000005 локально НЕ исполнялась — верификация в P4.2/P4.7.
- R7 (голый `*` из кастомной MergeStrategy при wildcard.enabled=true) — закрыть в
  P2.9 при wildcard-флипе D18.
- Premис-дефект бэклога «firstOrCreate второй аргумент = safe path» — при опоре на
  REGISTER перепроверять сигнатуры (запинён тестом f0055ae).
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; rmdir заблокирован хуком среды —
  пустые каталоги удалялись через python os.rmdir (не влияет на git-дерево).
- deferred: R9 upgrade-нота C-10 → P3.3 (D21); R12 reverse-parity сигнатуры → P2;
  RoleResource Livewire-тест (P1.2 Pending Work); split/Packagist (D25);
  roave/bc-check (D20); снапшот filament/context (P3.2);
  `removeScopedRoleEverywhere()` → контракт (P2); rename tests/Unit/Support/ →
  P2.10; прямой unit-тест SimplePermissionDefinition::label() + generics
  `Contracts\HasRoles::roles()` → P2.3/P2.10 (P2.2 Pending Work).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
