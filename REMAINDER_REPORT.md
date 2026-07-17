# Remainder Report — PR #91 (Фазы 5–8 + хвосты)

Статус по завершении PR #91. Все пункты F15–F54 (Фазы 5–8, см. `IMPROVEMENT_PLAN.md`) закрыты —
факт см. колонку `Status` в плане. Ниже — честный статус хвостов (T1–T7), которые PR **не закрывает**:
заголовок/описание PR были скорректированы, чтобы не заявлять «+ tails» как выполненные.

| ID | Sev | Статус | Что |
|---|---|---|---|
| T1 | 🟢 | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P1.1) | Eloquent global query-scope (`HasScopedRoles.php`, `bootHasScopedRoles`) теперь panel-aware — сужение по панели строго аддитивное (D5); заодно исправлена рекурсия eager-load `scopeEntity` (D9). |
| T2 | 🟡 | Открыт | `removeScopedRole($role, $entity, panelId=null)` сносит строки ВСЕХ панелей — асимметрия с `assignScopedRole` (там `null` = отдельная any-panel строка). Продуктовое решение по семантике не принято, только задокументировано в докблоке. |
| T3 | ⚪ | Открыт | `EnumPermissionCatalogBuilder` тихо `continue`ит на missing-классе без `Log::warning`, тогда как `PolicyAbilityCatalogBuilder` логирует — несимметрия диагностики подтверждена (см. `Registry/Builders/EnumPermissionCatalogBuilder.php:61,105` vs `PolicyAbilityCatalogBuilder.php:62`). |
| T4 | ⚪ | Открыт | В wildcard-off ветке `filterAgainstCatalog()` литеральный `*` в грант-ключе (`str_contains($key, WILDCARD)` не проверяется на этом пути) всё ещё матчится против dynamic `{seg}`-определений — докблок обещает «treated as unknown exact key». Подтверждено в `EffectivePermissionResolver.php`. Вред нулевой (ключ инертен при выключенном wildcard), но противоречит документации. |
| T5 | ⚪ | Открыт | Миграция `2026_01_01_000004_make_scope_class_nullable_on_model_has_scopes.php`: `down()` (`nullable(false)`) упадёт на MySQL/PostgreSQL при наличии null-строк — уже задокументировано в докблоке миграции; explicit rollback-теста нет. |
| T6 | 🟢 | **Закрыт** (план `2026.07.17-AZGUARD-TAILS`, P1.2) | `PermissionCache::forgetForUser()` теперь сериализует `add()`→`increment()`→`put()` под `Cache::lock()` (`LockProvider`-guard, graceful degradation на кастомных драйверах без лока) — конкурентные `forget()` больше не могут откатить эпоху. Реальный кросс-процессный Redis-тест на гонку — за scope, вынесен в follow-up (см. handoff). |
| T7 | ℹ️ | YAGNI | `resolveFor` пересчитывает panel/enums per-role в цикле (in-memory, не N+1/DB). Не трогать без реальной нагрузки. |

## Что закрыто в этом PR помимо F15–F54

- **Merge `origin/refactor/plan-remainder`** (PR #92, commit `bfc6813`) — без конфликтов; полное
  дерево прогнано через `composer test` (545/545), `pint --test`, `phpstan analyse` (0 ошибок),
  `bin/docs-parity-gate.sh` — все зелёные.
- **Ревью `bfc6813`**: breaking-удаление публичного `Registry\Exceptions\InvalidCatalogException` и
  фикс `guard:doctor` enum-role краша — задокументированы в `packages/core/CHANGELOG.md` (не было
  записи до этого PR); `docs/advanced/exceptions.md` (EN+RU) больше не описывают удалённое исключение.
- **Ревертнут `6cd9cc1`** — приватная AI-dev инфраструктура (`.claude/`, `.serena/`, `.swissknifeman/`,
  `.mcp.json.example`, `skills-lock.json`, `docs/05_AI/` и др.) снова в `.gitignore`; 403 файла
  untracked (с диска не удалены).
- **F12 переоткрыт и исправлен** (`5d215cb`): реализация 2026-07-02 была silent no-op (4 сайта читали
  незарегистрированное дерево `az-guard.filament.user_label_column`, вместо мёрженного
  `az-guard-filament.user_label_column`). Найдено ревью, добавлен regression-тест, исправлено.
- **CHANGELOG-мислейбл** (`a29b816`): F41-строка в `packages/filament/CHANGELOG.md` на самом деле
  описывала F12; F39-строка обещала несуществующий `config('az-guard.default_panel')` — переписано
  на реальный `config('az-guard-filament.panel')`.

## Рекомендация

T1 заслуживает собственного среза до того, как кто-то станет полагаться на scoped-query-filtering
между панелями (та же граница изоляции, что и F8). T6 — второй по приоритету (свежерегрессия, не
предсуществующий баг). T2 — продуктовое решение по семантике `removeScopedRole`. T3–T5, T7 — дешёвый
батч в конце очереди.
