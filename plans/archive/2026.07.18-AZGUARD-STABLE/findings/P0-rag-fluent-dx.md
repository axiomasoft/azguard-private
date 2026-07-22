# RAG-добор первоисточников: fluent API / DX конвенции (P0.1, 2026-07-18)

> Слой 1, добор к `findings/P0-rag-fluent-dx-preseed.md` (preseed НЕ редактируется).
> Лестница: context7 — ДОСТУПЕН (ключ активен), все несущие вердикты добраны по
> первоисточникам docs; WebFetch — только для laravel/framework PR #52679.
> Дата обращения ко всем источникам: 2026-07-18. Тезисы — только из
> research/01-fluent-api-priors.md; новые темы не добавлялись.

## Запрос 1 — Filament ^5 plugin-конвенции (context7, /websites/filamentphp_5_x)

Пин репо: `packages/filament/composer.json` → `"filament/filament": "^5.0"`; docs 5.x — соответствуют.

**Источники:**
- https://filamentphp.com/docs/5.x/plugins/panel-plugins — 2026-07-18 — первоисточник, вердикт ниже
- https://filamentphp.com/docs/5.x/plugins/configurable-resources-and-pages — 2026-07-18 — подтверждающий

**Вердикты:**
- RAG:✅ **Plugin-идиома v5 — fluent, официально документирована**: контракт
  `Filament\Contracts\Plugin` (`getId()` / `register(Panel $panel)` / `boot(Panel $panel)`);
  статический `make()` каноном реализуется через `app(static::class)` (swap в рантайме);
  конфигурация — fluent-сеттеры на объекте плагина (`return $this`) + геттеры, чтение
  настроек — в `register()`; регистрация — `$panel->plugin(BlogPlugin::make())`, включая
  вложенные fluent-цепочки (`TasksPlugin::make()->taskResources([...])`).
  → ⚠️ preseed «Filament plugin fluent API» ЗАКРЫТ первоисточником: **подтверждён**.

## Запрос 2 — spatie/laravel-permission: enum-граница, `::using()`, локус API (context7, /websites/spatie_be_laravel-permission_v6)

**Источники:**
- https://spatie.be/docs/laravel-permission/v6/basic-usage/enums — 2026-07-18 — первоисточник enum-границы
- https://spatie.be/docs/laravel-permission/v6/basic-usage/middleware — 2026-07-18 — первоисточник `::using()`
- https://spatie.be/docs/laravel-permission/v6/basic-usage/basic-usage — 2026-07-18 — первоисточник локуса (HasRoles)
- https://spatie.be/docs/laravel-permission/v6/basic-usage/direct-permissions — 2026-07-18 — Gate `$user->can()`
- https://github.com/laravel/framework/pull/52679 — 2026-07-18 (WebFetch) — `Authorize::using(BackedEnum)`, merged Laravel 11.x, 2024-09-06

**Вердикты:**
- RAG:✅ **`string|BackedEnum` — канон границы**: spatie v6 принимает BackedEnum напрямую в
  `assignRole/removeRole/givePermissionTo/revokePermissionTo/hasPermissionTo/hasAnyPermission/
  hasDirectPermission/hasRole/hasAllRoles/hasExactRoles`; для Gate/Blade до Laravel 11.23 —
  ручной `->value`. Laravel: `Authorize::using(Abilities::VIEW_DASHBOARD)` (PR #52679).
- RAG:✅ **Статические конструкторы middleware — документированная конвенция**:
  `RoleMiddleware::using('manager')`, `PermissionMiddleware::using('publish articles|edit articles')`,
  `RoleOrPermissionMiddleware::using(['manager', 'edit articles'])`; строковый alias-DSL
  (`role:manager,api`) сосуществует параллельно, оба пути в docs.
- RAG:✅ **Локус API — трейт + модели + родной Gate, фасада нет**: basic-usage начинается с
  `use HasRoles;` на User; introduction: «Permissions are registered with Laravel's native
  Gate system» → проверки через `$user->can('edit articles')` / `@can`; ни одного фасада
  в потребительском пути docs.

## Запрос 3 — fake-фасад с ассерциями (context7, /websites/spatie_be_laravel-pdf_v2)

**Источники:**
- https://spatie.be/docs/laravel-pdf/v2/basic-usage/testing-pdfs — 2026-07-18 — первоисточник паттерна
- https://spatie.be/docs/laravel-pdf/v2/basic-usage/queued-pdf-generation — 2026-07-18 — ассерции очереди

**Вердикты:**
- RAG:✅ **Fake-фасад + high-level ассерции — документированный spatie-паттерн**:
  `Pdf::fake()` → `assertSaved(callable)/assertViewIs/assertSee(array|string)/
  assertRespondedWithPdf(callable)/assertQueued(string|callable)/assertNotQueued`;
  ассерции простые ИЛИ closure-предикат над builder'ом (`fn (PdfBuilder $pdf) => ...`);
  «get log»-метода нет — только ассерции. Подтверждает preseed-описание Recorder-паттерна.

## Статус 5 вердиктов preseed

| # | Вердикт preseed (приор) | Статус | Первоисточник |
|---|---|---|---|
| 1 | Fake-фасад с ассерциями — стандарт; у azguard гэп `AzGuard::fake()` (A.1) | **подтверждён** RAG:✅ | laravel-pdf v2 testing-pdfs + queued-pdf-generation |
| 2 | Fluent для поведения, config для wiring (B.8) | **подтверждён** RAG:✅ (Filament-часть — первоисточником; общий максим «no DSL in config» — по-прежнему синтез, см. Ограничения) | filamentphp 5.x panel-plugins (fluent-сеттеры плагина, не config-массивы) |
| 3 | `string\|BackedEnum` — канон границы permission/role (A.4) | **подтверждён** RAG:✅ | laravel-permission v6 enums + framework PR #52679 |
| 4 | Статический `::using()` у middleware — конвенция; у azguard только алиасы — гэп (A.5) | **подтверждён** RAG:✅ | laravel-permission v6 middleware |
| 5 | Локус API — трейт/модели/Gate, фасад не центр (корректировка B.7) | **подтверждён** RAG:✅ (корректировка preseed остаётся в силе) | laravel-permission v6 basic-usage + direct-permissions |

Дополнительно: ⚠️-пункт preseed «Filament plugin fluent API» — **подтверждён** первоисточником (Запрос 1), раздел «Ограничения» preseed в этой части закрыт.

## Входы для осей (P0.2 / P0.3)

- **P0.2 / C-A8**: сравнить FakeAzGuardUser/FakeGrantSource с каноном `Pdf::fake()`:
  есть ли одно-вызовный swap через фасад, high-level ассерции (`assertGranted/assertDenied/
  assertChecked`) и closure-вариант? Ожидаемый гэп — отсутствие `AzGuard::fake()`.
- **P0.2 / C-A3**: у spatie alias-DSL плоский (`role:manager,api` — имя+guard); вопрос к коду:
  параметры 5 алиасов azguard сложнее этого? Если да — читаемость ниже канона.
- **P0.2 / C-A2, C-A4**: проходит ли путь потребителя целиком через трейт+Gate (как у spatie),
  или docs/примеры делают фасад центром? Считать вхождения фасада в quick-start.
- **P0.3 / полиморфизм границы**: все ли входы (фасад, трейты, middleware-параметры,
  builder'ы) принимают `string|PermissionKey|BackedEnum` с unwrap на границе (канон spatie:
  методы enum-aware, persistence через `->value`)?
- **P0.3 / C-B7**: сигнатурный образец для гэпа статических конструкторов:
  `using()` принимает строку, pipe-строку или массив (spatie) и BackedEnum (Laravel Authorize).
- **P0.3 / Filament-шов**: AzGuardPlugin против канона: `make()` через `app(static::class)`?
  fluent-сеттеры `return $this` + геттеры? чтение опций в `register()`? опции плагина
  не задублированы в config-массиве?

## Ограничения

- Общий максим «no DSL in config» (часть вердикта 2) как универсальное правило spatie
  2024–2026 остаётся Perplexity-синтезом preseed — отдельного первоисточника-манифеста нет:
  [UNVERIFIED] как обобщение; Filament-частный случай подтверждён docs.
- Doc-DX-вердикт preseed (Basic usage ≤5 строк / Testing / Advanced) — не входил в 5 несущих,
  первоисточник не добирался: остаётся синтезом ([UNVERIFIED] как норматив, использовать
  в C-A11 как эвристику, не как канон).
- context7-выдача — выдержки docs, не полные страницы; URL первоисточников указаны и
  проверяемы напрямую.
