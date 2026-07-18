# RAG-preseed: fluent API / DX конвенции Laravel-пакетов (2026-07-18)

> Слой 1. RAG-верификация приоров `research/01-fluent-api-priors.md`, выполнена в
> design pass 1 (лестница: context7 — квота/ключ активируется со следующей сессии →
> Perplexity web, 2 запроса). P0.1 остаётся: доверификация Filament-конвенций через
> context7 + углубление. Дата: 2026-07-18.

## Запрос 1 — fluent vs config, fake-фасады, DX-конвенции

Источники: spatie/laravel-pdf docs (testing-pdfs), spatie.be laravel-screenshot
testing, spatie.be laravel-health testing, spatie/laravel-markdown-response docs,
обзор «best Laravel packages 2025».

**Вердикты:**
- ✅ **Fake-фасад с ассерциями — устоявшийся стандарт 2024–2026**: `Pdf::fake()` +
  `assertSaved/assertViewIs/assertSee`, `Screenshot::fake()` + `assertSaved/
  assertQueued`, `Markdown::fake()` + `assertConverted*`, `Health::fake([...])`.
  Паттерн: fake свапает Recorder в контейнер, копит инвокации, ассерции простые +
  вариант с closure-предикатом; «get log»-метода нет — только high-level ассерции.
  → Подтверждает приор A.1 (у azguard нет `AzGuard::fake()` — гэп).
- ✅ **Fluent-builders для поведения, config — для wiring**: поведение per-call — во
  fluent-объектах/цепочках; config — драйверы, дефолты, feature-флаги; «no DSL in
  config» — сложные описания живут в PHP-классах, не в массивах.
  → Подтверждает приор B.8.
- ✅ **Doc-DX**: секции Basic usage (install→результат ~5 строк), Testing (fake +
  ассерции), Advanced отдельно. → вход для оси A (аудит docs quick-start).
- ⚠️ **Filament plugin fluent API** — подтверждено только обзорными статьями
  («fluent registration, не config-массивы»), первоисточник (docs Filament) не
  прочитан: квота context7. → доверифицировать в P0.1 (ключ уже прописан).

## Запрос 2 — enum'ы, middleware-конструкторы, локус API

Источники: github spatie/laravel-permission (+issue 2805, discussion 2795, release
6.24.0), laravel/framework PR #52679, medium-обзор.

**Вердикты:**
- ✅ **`string|BackedEnum` — канонический тип имени permission/role на границе API**:
  spatie 6.x/7.x принимает enum'ы во всех проверках/присвоениях (assignRole,
  givePermissionTo, hasPermissionTo, hasRole, hasAnyPermission…), внутри unwrap
  `->value`; для persistence — `enum_value()`. Laravel: `Authorize::using()` принимает
  BackedEnum (framework PR #52679). → Подтверждает приор A.4.
- ✅ **Статические конструкторы middleware — конвенция**: spatie
  `RoleMiddleware::using('manager')` / `PermissionMiddleware::using(...)` /
  `RoleOrPermissionMiddleware::using([...])` + Laravel 10.9+ `Authorize::using(...)`;
  интеграция с Laravel 11 `HasMiddleware`. Строковый alias-DSL остаётся параллельно.
  → Подтверждает приор A.5 (у azguard только строковые алиасы — гэп).
- ✅→корректировка **приора B.7 (ширина фасада)**: у spatie/laravel-permission НЕТ
  широкого фасада вовсе — API живёт на трейте User (HasRoles), моделях Role/Permission
  и родном Gate (`$user->can`, `@can`, `can:` middleware); фасад не является центром.
  Вывод для azguard: «идеал» — не «фасад с fluent-корнями», а Laravel-native локус
  (трейт + Gate) с УЗКИМ фасадом для оркестровых операций (panels, catalog, grants).
  Ширину 18 @method мерить против этого, а не против «3–4 корней».

## Ограничения

- context7 (первоисточники docs) не использован: квота исчерпана; API-ключ владельца
  прописан в ~/.claude.json (headers) — активен со следующей MCP-сессии.
- Filament plugin-конвенции — слабое покрытие (см. выше), обязательный пункт P0.1.
- Perplexity-ответы — синтез с источниками; несущие решения P2 должны ссылаться на
  первоисточники, добранные в P0.1.
