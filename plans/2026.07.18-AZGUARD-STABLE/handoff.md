# HANDOFF — 2026-07-18 — after P2.3

**Next:** ЗАПУСК ВРУЧНУЮ: `sonnet/high` (manual, Routing построчный после D28).
Следующий item — P2.4 (config→fluent: Filament-плагин fluent-сеттеры + middleware
`::using()` + единый порядок аргументов; каноны запинены D17). Порядок §10 канона:
P2.3 ✓ → P2.4/P2.6…/P2.5 → P2.9 → P2.10. Модельная карта остатка (D28): fable —
P2.5/P2.9/P3.2/P5.1; sonnet/high — P2.4/P2.6/P3.1/P3.3; sonnet/medium plan-exec —
P2.7/P2.8/P2.10/P4.1–P4.6/P5.2/P5.3; sonnet/high — P4.7.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — предписанная реализация публичного API по запинённым RAG-канонам (D17), открытых design-решений нет |
| Context | continue (/clear) — ручной item |
| Суть | P2.4: Filament-плагин fluent + `app(static::class)`, middleware `::using(string\|BackedEnum)`, выравнивание порядка аргументов `что,где` |

```
/model sonnet
/effort high
/task:plan-run 2026.07.18-AZGUARD-STABLE P2.4
```

(Форма D18: модель/effort сессии должны соответствовать Routing — гейт plan-run сверит.)

**Done:** P2.3 закрыт 🟠 (item-коммит `278cdfc`, 19 files, +673/−84): единый immutable
fluent-корень грантов core↔context (D16). `AzGuard::forUser($u)->on()->inContext()->until()
->grant()` — одна грамматика; core context-агностичен через registered-extension шов
(`Contracts\ContextGrantBuilder` + `Contracts\ContextGrantBuilderFactory` @api, биндит
context-SP; без пакета — `ContextPackageNotInstalledException`, fail-closed). Оба builder'а
`final readonly` + with-сеттеры; **breaking**: `expiresAt()` → `until()`. TTL-парность
context: миграция `expires_at` (000011), `until()/ttl()`, идемпотентный re-stamp
(updateOrCreate), `active()`-фильтр в `grants()` И в read-пути ContextPermissionLayer.
Shorthands grant/revoke/grants → @internal (manager+interface+фасад), docs EN переписаны
на корень (попутно снята стухшая wildcard-претензия context.md). Арх-ратчет расширен
(ArchTest 17). Validation на 278cdfc: targeted 218 passed · arch 17 · feature 334 ·
analyse 0 errors · lint passed · полный `composer test` 623 passed / 1681 assertions.
Отклонения: дифф вне перечня Files (новый шов Contracts/, interface @internal, SP-биндинг,
фильтр layer) — гэп перечня, предписан Code Guidance самого ТЗ; детали phases/P2.md
P2.3 Known Deviations.

**Remaining:** P2.4–P2.10 (fable/high manual, по одному item на сессию; порядок —
research/03-p2-canon.md §10: P2.4/P2.6…/P2.5 → P2.9 → P2.10 последним) →
P3 заморозка → P4 тест-углубление → P5 (шаблон → релиз+тег → миграция docs) →
post-plan `/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.8, D1–D27) ·
phases/P2.md (P2.1 🟠, P2.2 🟠, P2.3 🟠, ТЗ P2.4–P2.10) · research/03-p2-canon.md (канон
структуры и грамматики, §4 config→fluent, §10 порядок) · findings/P0-rag-fluent-dx.md
(Запросы 1–2 — RAG-каноны P2.4) · root/architecture.md (ADR структуры) · findings/
(REGISTER + оси) · roadmap.md · brief/{00-brief,01-refinements}.md.

**Open risks:**
- RU-зеркала context.md/direct-grants.md рассинхронены с EN после P2.3 — паритет
  закрывает P2.10 (`docs-parity-gate` до тех пор красен по этим страницам).
- P2.5 (cut-line спека) обязана согласоваться с P2.3: shorthands уже @internal,
  «оставить» для forUser — вердикты сверять с фактом кода, не только с C-B4.
- Новые FQCN после P2.1 (Panels/Permissions/…) — Required Reads P2.4+ могут ссылаться
  на старые пути; расхождение путей — следствие P2.1, не дефект.
- tests/Unit/Support/ — имя каталога дрейфует от канона (Pending Work P2.1, кандидат P2.10).
- MySQL-ветка миграции 000005 локально НЕ исполнялась — верификация в P4.2/P4.7;
  новая миграция 000011 (expires_at) гонялась только на sqlite — та же верификация.
- R7 (голый `*` из кастомной MergeStrategy при wildcard.enabled=true) — закрыть в
  P2.9 при wildcard-флипе D18.
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; rmdir заблокирован хуком среды —
  пустые каталоги удалялись через python os.rmdir (не влияет на git-дерево).
- deferred: R9 upgrade-нота C-10 → P3.3 (D21); RoleResource Livewire-тест (P1.2
  Pending Work); split/Packagist (D25); roave/bc-check (D20); снапшот
  filament/context (P3.2); `removeScopedRoleEverywhere()` → контракт (P2);
  rename tests/Unit/Support/ → P2.10; прямой unit-тест
  SimplePermissionDefinition::label() + generics `Contracts\HasRoles::roles()` →
  P2.10 (P2.2 Pending Work); RU-зеркала P2.3 + дог-фуд корня в context-CLI → P2.10
  (P2.3 Pending Work).
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
