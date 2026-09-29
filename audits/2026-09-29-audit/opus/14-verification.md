# 14 — Сценарии проверки

Каждый сценарий — исполняемый тест (Pest), если не помечено «стенд». Колонка «Probe» — какой probe из
[evidence](evidence/README.md) сценарий инвертирует (красный на 0.3 → зелёный после пункта).

## Идентичность и грамматика

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V01 | Property: для случайных `(type, id)` без `:` в типе `ContextRef::key()` инъективен; `of('w', 7) ≡ of('w', '7')`; `of('w:a', 7)` → `InvalidContextException` | P2.1, P1.8 | P07 |
| V02 | Property: `PermissionKey::from((string) $k) ≡ $k`; неквалифицированная строка → `UnqualifiedPermissionException`; верхний регистр/пробелы/`*` без realm → `InvalidPermissionKeyException` | P2.1–P2.2 | — |
| V03 | Таблица шаблонов: `app.docs.*` покрывает `app.docs.view`, не `app.docs.a.b`; `app.**` покрывает всё в `app`, не в `admin`; `**` не последний сегмент → ошибка | P2.2 | — |
| V04 | Digest ключей кэша для двух разных `(subject, realm, contexts)` различен (1e5 случайных) | P2.1, P5.5 | P07 |

## Realm, каталог, роли

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V05 | Повторная регистрация realm → `DuplicateRealmException`; `replace()` до freeze работает; после `booted` → `RegistryFrozenException` | P4.1 | Codex C08 |
| V06 | Realm id `''`, `a.b`, `*`, `A`, `a b` отвергаются | P4.1 | Codex C08 |
| V07 | Enum без `#[Realm]` и не перечисленный в realm → boot-ошибка; enum в двух realm → ошибка | P4.1 | — |
| V08 | Два провайдера с одним ключом и разным label → `DuplicatePermissionException`; одинаковые → ок | P4.2 | — |
| V09 | Переименование класса роли (ключ тот же) + `roles:sync` → назначения сохраняются, проверки держателей работают | P4.3 | P02 |
| V10 | Смена ключа с `formerKeys()` → назначения переносятся; без `formerKeys` → новая роль, старая в отчёте `--prune` | P4.3 | — |
| V11 | `definition` указывает на несуществующий класс → проверка держателя не бросает; роль пустая; doctor error | P4.3, P1.6 | P02 |

## Контексты

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V12 | Таблица [09 §2](09-authorization-semantics.md#2-политика-контекстов-realm) целиком (4 политики × 3 столбца) | P4.4, P5.2 | — |
| V13 | `isolated('workspace')` в `app` и `none()` в `admin`: админ-права работают без контекста при ambient `workspace:1` | P4.4 | P06 |
| V14 | `withinContext()`: исключение внутри callback и исключение резолвера/кэша на входе → прежний контекст восстановлен | P4.4 | Codex C02 |

## Движок

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V15 | Property-тесты P1–P7 ([09 §1](09-authorization-semantics.md#1-алгоритм-decideaccessrequest-r)) на генерируемых назначениях | P5.2 | P01, P08 |
| V16 | Membership-constraint: пример «Анна/Борис/Вера» из 09 §2; исключение в constraint → `Deny(ConstraintError)` | P5.3 | — |
| V17 | Роль `app` с `is_superadmin` не действует в `admin`; `*:superadmin` действует везде; superadmin не обходит `bypassable = false` | P5.4 | P01a |
| V18 | Кэш: после commit отзыва новая проверка (новый request) — Deny; при `state_refresh = check` — Deny в том же request; внутри незакоммиченной мутации — read-your-writes | P5.5 | P10b |
| V19 | Бюджеты D44: повторная проверка = 0 запросов; 10 проверок при тёплом кэше и `state_refresh = request` = 1 запрос ревизии | P5.5 | P10 |

## Администрирование и Filament

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V20 | Актор с `app.azguard.assignments.manage`, но без `app.docs.delete` не может назначить роль, содержащую `app.docs.delete` (`delegation_denied`) | P6.2, P8.3 | N02 |
| V21 | Актор не может выдать себе право, которого нет (включая через роль с меньшим rank) | P6.2 | N02 |
| V22 | Только superadmin realm назначает superadmin-роли и выдаёт `x.**` | P6.2 | P14 |
| V23 | Livewire-payload с `class_name`/`definition` в `RoleResource` игнорируется/отвергается; code-роль нельзя изменить кроме label | P8.3, P1.5 | N02 |
| V24 | Две Filament-панели с разными realm: конфиг не перезаписывается, решения независимы | P8.1 | N18 |
| V25 | Страница с `AuthorizesPage` при `enforce`: пользователь без права и пользователь без `AzGuardSubject` → 403 | P8.2 | N18 |
| V26 | Поиск субъекта при 10 000 пользователях: 1 запрос с `LIMIT 50`; подпись субъекта при morph map корректна | P8.4 | N18 |
| V27 | `explain()` совпадает с `decide()` на матрице 1000 запросов; не делает запросов к источникам сверх `decide` | P5.7 | Codex C05 |

## Gate, видимость, события

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V28 | Authoritative: своя ability без гранта → `false` даже при наличии Laravel-политики с тем же именем; чужая (`update`) → `null`, политика хоста работает | P5.8 | N19 |
| V29 | `Gate::allows('admin.users.ban')` на запросе с ambient-контекстом `app` → решение по realm `admin` | P5.8 | P09 |
| V30 | Любой вход (`hasPermission`, `AzGuard::check`, Gate, `@can`, `azguard.can`, `decideMany`, CLI `explain`) даёт одно решение на матрице запросов | P5.8, P7.1 | P03 |
| V31 | `visibleTo`: без субъекта → пусто; пользователь без назначений и без глобального права → пусто | P5.9 | P04a, P04b |
| V32 | `visibleTo`: роль в двух проектах → оба видны; глобальное право → все | P5.9 | P04c |
| V33 | `visibleTo` на 100 000 строк использует индекс `azg_role_assignments_context_idx` (EXPLAIN на PG/MySQL) | P5.9 | — |
| V34 | Каждая операция `AccessManager`: событие после commit, не при откате; no-op без события; `eventId` уникален; payload без моделей | P6.3 | P11 |
| V35 | `features.audit`: строка аудита в той же транзакции (откат → нет строки) | P6.3 | — |

## Конфигурация, установка, среда

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V36 | Нормализатор: каждый ключ 03 §8 → новый + `E_USER_DEPRECATED`; конфликт → исключение; boot-проверки 07 §4 | P7.3 | — |
| V37 | `azguard:install`: без `--migrate` не запускает `migrate`; ошибка `migrate` → код выхода ≠ 0 | P7.5 | Codex C10 |
| V38 | Octane (стенд): два запроса разных субъектов/контекстов на одном воркере — нет утечки контекста и кэша | P4.4, P5.5 | — |
| V39 | Queue: job без явного контекста не видит ambient прошлого job; `withinContext` в job | P4.4 | — |

## Upgrade

| # | Сценарий | Пункт | Probe |
|---|---|---|---|
| V40 | Фикстура 0.3 со всеми строками таблицы [08 §5](08-data-model-and-migration.md#5-upgrade-03x--040) → `--dry-run` отчёт совпадает с эталоном | P3.4 | — |
| V41 | `--execute` на PG и MySQL; матрица решений до/после совпадает, кроме строк «изменение поведения» | P3.4 | P01a |
| V42 | Повторный `--execute` — no-op; `down()` восстанавливает 0.3 из `*_legacy_03` | P3.4 | — |

## Производительность и консистентность (стенд)

| # | Сценарий | Пункт |
|---|---|---|
| V43 | p95/p99 `decide()` холодный/тёплый кэш, 1/10/100 назначений на субъекта (бенчмарк R17) | P5.5 |
| V44 | Конкуренция записей: 50 параллельных `assignRole` — нет deadlock без ретрая сверх 3, ревизия монотонна | P3.1 |
| V45 | `decideMany` на 1000 узлов Vaulter-листинга — ≤ бюджета D44 | P5.6 |
| V46 | Primary + реплика с задержкой: после commit отзыва новая проверка → Deny при `reads = primary`; при `default` — задокументированное окно (F32) | P1.13, P5.5 |

## Экосистема (стенд E6)

| # | Сценарий | Пункт |
|---|---|---|
| V50 | `vaulter-azguard` с зарегистрированным realm `app` и картой квалифицированных ключей: workspace-лейн даёт уровни Reader…Owner | P9.5 | 
| V51 | Карта с ключом вне каталога → boot-ошибка моста, doctor Vaulter показывает `azguard.bridge` error | P9.5 |
| V52 | Листинг Vaulter на 1000 узлов → один `decideMany` на страницу; кэш Vaulter с `AzGuard::state()` инвалидируется при отзыве | P9.5 |
