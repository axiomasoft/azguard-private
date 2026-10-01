# AzGuard — обратная связь по интеграции

Собрано при написании моста `vaulter-permissions-azgard` (адаптер, отдающий права из AzGuard в composite-ACL Vaulter) + сквозном аудите consumer-facing поверхности AzGuard.

Формат каждого пункта: **как использую → проблема → возможное решение**. Приоритет: 🔴 высокий · 🟠 средний · 🟢 низкий. Метка достоверности:
- ✅ **подтверждено** — проверено чтением кода (file:line);
- 🔎 **проверить** — найдено аудитом, не перепроверял глубоко;
- ⚠️ **под вопросом** — вероятно ложное срабатывание, оставил для очной проверки.

Пункты 1–8 — из практики написания моста; 9–20 — из аудита.

---

## Статус решения (0.2.0)

Проверено против живого кода и реализовано в ветке `refactor/integration-polish`.

- ✅ **Решено:** 1 (контракт `AzGuardUser` + сегрегированные интерфейсы), 3 (`isSuperAdmin`
  на акторе/фасаде/менеджере + рецепт `Gate::before`), 5 (`extra.branch-alias` во всех
  сателлитах + lockstep 0.2.0), 6 (`hasContextGuard()` + once-per-request warning, Octane-safe
  scoped-дедуп), 7 (`FakeAzGuardUser` + публичный `AzGuard\Testing`), 9 (`hasGrant()` резолвит
  дефолтную панель), 10 (`panelIdForPermission` — enum резолвится в свою панель), 11
  (`PermissionKey::WILDCARD`/`SEPARATOR`), 12 (комментарий про `'app'` fallback в конфиге),
  13 (`morphType()` бросает `InvalidMorphTypeException` + валидация в boot), 14
  (`registerCustomCatalogBuilders` hook), 15 (debug-лог отброшенных каталогом ключей), 17
  (`PermissionSet` помечен `@api` как стабильный), 19 (`GrantBuilder::expiresAt()` — паритет TTL),
  20 (debug-warning на незарегистрированную панель).
- 📝 **Решено документацией:** 4 (headless-путь: каталог уже lenient + `FakeGrantSource`/`FakeAzGuardUser`
  рецепт), 8 (id/morph — единый раздел в доке; рантайм-сеттер сознательно не добавлен, чтобы не
  раздувать интерфейс менеджера — morph_type влияет только до миграций).
- 🔁 **Пересмотрено:** 2 — по итогам ресёрча (LSP/публичный API) `Model` **оставлен** (типобезопасность
  идиоматична для Eloquent-пакета); интероп решается контрактом `AzGuardUser` + рецептом адаптера,
  а не ослаблением типа до `object`.
- ❌ **Снято (не баг):** 18 — direct-grant и `ScopedRoleCache` работают с непересекающимися данными;
  глобальная ветка `hasScopedPermission` уже флашится через `PermissionCache`, scoped-ветка некэшируема.

---

## Часть I. Точки трения из интеграции (моста)

### 🔴 1. Нет публичного интерфейса актора (только трейты) ✅
**Как использую.** Нужен тип, на который тайпхинтится «пользователь, умеющий в AzGuard», чтобы адаптер вызывал `hasPermission`/`hasPermissionIn`/`hasScopedPermission`.
**Проблема.** Возможности в трейтах `HasAzGuard`/`HasPermissions`/`HasScopedRoles`; на трейт не затайпхиниться, `HasAzGuard` не реализует интерфейса (`src/Concerns/HasAzGuard.php` — `implements` нет). Каждый интегратор переобъявляет свой локальный интерфейс (я сделал `AzgardBackedUser`, продублировав сигнатуры 1:1) — хрупко при смене сигнатур.
**Решение.** Публичный `AzGuard\Contracts\AzGuardUser` с тремя методами, который `HasAzGuard`(+`HasScopedRoles`) `implements`. Ломающих изменений ноль (тела уже есть).
```php
interface AzGuardUser {
    public function hasPermission(string|\UnitEnum $permission, ?string $panelId = null): bool;
    public function hasPermissionIn(string $contextType, int|string $contextId, string|\UnitEnum $permission, ?string $panelId = null): bool;
    public function hasScopedPermission(string|\UnitEnum $permission, \Illuminate\Database\Eloquent\Model $entity, ?string $panelId = null): bool;
}
```

### 🔴 2. `hasScopedPermission($entity: Model)` ломает LSP у потребителей ✅
**Как использую.** Мой шов объявляет `hasScopedPermission(string, object $entity)` (без зависимости на Eloquent в контракте ядра). Хочу, чтобы User удовлетворял оба контракта одной реализацией.
**Проблема.** У AzGuard `$entity` типизирован `Model` (`src/Concerns/HasScopedRoles.php:163`). `Model` у́же `object` → по контравариантности параметров PHP это НЕ валидная реализация `object` → фатал «Declaration must be compatible». Юзер не может реализовать мой узкий шов; пришлось вводить пакет-локальный контракт + адаптер, сужающий `object→Model` вручную.
**Решение.** Принимать `object $entity` и валидировать `instanceof Model` внутри; либо ввести узкий тип-маркер `AzGuard\Contracts\Scopeable` (только `getKey()/getMorphClass()`) и типизировать им.

### 🟠 3. Нет явного `isSuperAdmin(): bool` ✅
**Как использую.** Vaulter спрашивает «супер-админ?» для absolute-allow override.
**Проблема.** Метода нет; супер-админ выводится через `hasPermission('*')`. Интегратор обязан знать вайлдкард-конвенцию; захардкодил `'*'` дефолтом.
**Решение.** Первоклассный `isSuperAdmin(?string $panelId = null): bool` на акторе/фасаде (+ конфиг ключа вайлдкарда).

### 🟠 4. Проверка одной пермишн требует зарегистрированной панели + каталога ✅
**Как использую.** Headless-проверка одного строкового ключа (`documents.view`) в интеграционном тесте / встраиваемом сценарии.
**Проблема.** Нужен `PanelProvider` + `permissionEnums` + `EnumPermissionCatalogBuilder`, тегированный в контейнере (`src/PanelProvider.php`, `TestGuardPanelProvider` в тестах). Для встраиваемой библиотеки — тяжёлый порог. **Именно из-за этого** не стал гонять полный real-AzGuard e2e моста.
**Решение.** «Динамический»/panel-less путь: принимать произвольный ключ без обязательного каталога (`AzGuard::allows($user,$key,$context)` в lenient-режиме или флаг `az-guard.strict_catalog=false`).

### 🟡 5. Нет `branch-alias`/версий → path-repo потребители не резолвятся ✅
**Как использую.** Подключаю `azguard-core`+`azguard-context` как path-repo dev-deps.
**Проблема.** `azguard-context` требует `azguard-core: ^0.1`, но у пакетов нет `version`/`extra.branch-alias` → path-repo отдаёт `dev-main`, что не удовлетворяет `^0.1`. `composer` падает. Пришлось inline-алиасить (`dev-main as 0.1.99`).
**Решение.** В composer.json КАЖДОГО под-пакета:
```json
"extra": { "branch-alias": { "dev-main": "0.1.x-dev" } }
```

### 🟠 6. Молчаливый fallback `hasPermissionIn` при отсутствии `ContextGuard` ✅
**Как использую.** `hasPermissionIn($type,$id,$perm)` для workspace/tenant-проверки.
**Проблема.** Если `AzGuard\Contracts\ContextGuard` не забинжен (нет azguard-context/Corex), метод молча возвращает `false` (`src/Concerns/HasPermissions.php:60,73-76` — `contextGuard()?->checkInContext(...)`). Тихий false-negative, трудно дебажить.
**Решение.** Наблюдаемость: `hasContextGuard(): bool`, разовый `Log::warning`, либо строгий режим с понятным исключением.

### 🟢 7. Нет экспортируемого testing-kit ✅
**Как использую.** Тестировать интеграцию без панелей/миграций/каталогов.
**Проблема.** `FakeGrantSource` (`src/Testing/FakeGrantSource.php`) и стабы — внутренние, downstream ими не пользуется.
**Решение.** Вынести поддерживаемый `AzGuard\Testing` (`FakeGrantSource`, `FakeAzGuardUser`, in-memory grant-source c `->grant()/->wildcard()`) как публичный контракт для тестов.

### 🟢 8. Выравнивание id/morph размазано по трём конфигам ✅
**Как использую.** Настраиваю ulid-морфы под модели хоста.
**Проблема.** Согласование в трёх местах: `corex.ids.strategy`, `AZ_GUARD_MORPH_TYPE`, morph-map хоста (+ `EntityRegistry::register`). Легко рассинхронить → тихие «прав нет».
**Решение.** Одна точка/рецепт: хелпер `AzGuard::useUlidMorphs()` или единый резолвер стратегии id + раздел в доке.

---

## Часть II. Из сквозного аудита (файл:строка)

### 🟠 9. `hasGrant()` без `$panelId` резолвит пермишн против пустой строки ✅
**Файл.** `src/Concerns/HasDirectGrants.php:53-63`.
**Как использую.** `$user->hasGrant('documents.view')` (panelId по умолчанию `null`).
**Проблема.** Передаётся `PermissionName::resolve($permission, $panelId ?? '')` — пустая строка, а НЕ `PanelResolver::resolveDefault()` как в `hasPermission()`/`grant()`/`revoke()`. Плюс фильтр `panel_id` добавляется только если `$panelId !== null` → без панели ищется грант с сырым ключом по любой панели. Непоследовательно с остальным API.
**Решение.** Использовать `PanelResolver::resolveDefault($panelId)` и всегда фильтровать по `panel_id`.

### 🟠 10. `hasScopedPermission()` с enum-пермишн без `$panelId` берёт дефолтную панель, не панель enum'а 🔎
**Файл.** `src/Concerns/HasScopedRoles.php:163`.
**Как использую.** `$user->hasScopedPermission(DocumentsPermission::View, $entity)`.
**Проблема.** Для строк с точкой панель выводится из первого сегмента; для enum без точки — `resolveDefault()`, а не собственная панель enum'а. Молча резолвит не ту панель.
**Решение.** Либо в докблоке требовать явный `$panelId` для enum, либо определять панель enum'а через `AzGuardManager::tryPermission()` автоматически.

### 🟠 11. Вайлдкард `'*'` — магическая строка, нет экспортируемой константы ✅
**Файлы.** `src/Registry/Values/PermissionSet.php:32,52`, `src/Contracts/RoleInterface.php:20` (только докблоки/литералы).
**Как использую.** `hasPermission('*')`, `superAdminPermission: '*'` в адаптере.
**Проблема.** Нет `PermissionSet::WILDCARD`/`PermissionKey::WILDCARD` — потребители хардкодят `'*'`. Смена синтаксиса ломает всех молча.
**Решение.** Публичная константа `public const string WILDCARD = '*';`.

### 🟠 12. Хардкод-fallback `'app'` в `PanelResolver::resolveDefault()` не отражён в конфиге ✅
**Файл.** `src/Support/PanelResolver.php:32` (`$panelId ?? Config::defaultPanel() ?? 'app'`); конфиг `config/az-guard.php` `'default_panel' => null`.
**Как использую.** Хост без `default_panel` и активной панели.
**Проблема.** Незаметный `'app'` как последний fallback; читая конфиг, интегратор этого не видит → скрытый mis-config.
**Решение.** Комментарий в конфиге про fallback `'app'` (или упомянуть в README).

### 🟠 13. `Config::morphType()` молча откатывается на `'int'` при неизвестном значении ✅
**Файл.** `src/Support/Config.php:92-99` (`match … default => 'int'`).
**Как использую.** `AZ_GUARD_MORPH_TYPE=uuid_v7` (опечатка).
**Проблема.** Неизвестное значение молча становится `'int'` → миграции с int-id при ulid/uuid-моделях хоста → криптическая ошибка типов на первом запросе морфов.
**Решение.** Валидировать: бросать понятное исключение при значении не из `['int','ulid','uuid']`.

### 🟠 14. `PanelProvider::registerCatalogBuilders()` не расширяется без дублирования 🔎
**Файл.** `src/PanelProvider.php:83`.
**Как использую.** Хочу добавить свой catalog-builder (напр. из БД), не переписывая базовую enum/policy-логику.
**Проблема.** Единственный способ — переопределить protected-метод целиком (все параметры прокидывать), частичная кастомизация громоздкая.
**Решение.** Хук: базовый метод в конце зовёт пустой `registerCustomCatalogBuilders(Panel $panel)` для оверрайда.

### 🟠 15. Нет валидации, что ключи пермишн в роли есть в каталоге 🔎
**Файл.** `src/Registry/Resolver/EffectivePermissionResolver.php`.
**Как использую.** Роль объявляет `permissions(): ['app.posts.edit','app.posts.delete']` с опечаткой.
**Проблема.** Строковые ключи роли не сверяются с каталогом; опечатка молча игнорируется — пермишн недоступен, ошибки нет.
**Решение.** В резолвере фильтровать/логировать неизвестные (не-вайлдкард) ключи.

### 🟢 16. Формат ключа пермишн нигде формально не задокументирован 🔎
**Как использую.** `hasPermission('app.posts.edit')` vs `'posts.edit'` vs `'*'`.
**Проблема.** Формат `{panel}.{resource}.{action}` подразумевается примерами, но не специфицирован (докблока на `PermissionName`/в README нет). Кастомные enum/builder'ы рискуют разойтись в конвенции.
**Решение.** Раздел «Permission Key Format» в README/докблоке: dotted → первый сегмент = панель; flat → нужна явная панель; `'*'` → всё.

### 🟢 17. `PermissionSet` не экспортирован публично ✅
**Файл.** `src/Registry/Values/PermissionSet.php:5` (`namespace AzGuard\Registry\Values`).
**Как использую.** Возвращаемый тип контракта grant-source; кастомный grant-source импортирует из внутреннего namespace.
**Проблема.** Публичного алиаса в корневом `AzGuard\` нет — неожиданно для внешних интеграторов.
**Решение.** Публичный алиас `AzGuard\PermissionSet` (или задокументировать внутренний путь как стабильный).

### 🟢 18. Кэш scoped-ролей не сбрасывается при изменении direct-grant ⚠️
**Файл.** `src/Models/DirectGrant.php:49-52` (booted → только `PermissionCache::forgetForUser`), `src/Support/ScopedRoleCache.php` существует.
**Замечание.** Аудит утверждает, что при изменении `DirectGrant` не инвалидируется `ScopedRoleCache`. **Под вопросом:** direct-grant'ы и scoped-роли — разные данные; изменение гранта логически не должно влиять на кэш scoped-ролей. Скорее ложное срабатывание — оставил на очную проверку. Если пересечения кэшей нет — пункт снять.

### 🟢 19. Асимметрия TTL: `GrantBuilder->ttl(seconds)` vs `HasDirectGrants::grant($expiresAt: DateTime)` 🔎
**Файлы.** `src/Grants/GrantBuilder.php:50`, `src/Concerns/HasDirectGrants.php:73`.
**Проблема.** Один и тот же грант выдаётся двумя разными по стилю API (секунды-TTL vs DateTime). Переключаясь между фасадом и трейтом, потребитель встречает асимметрию.
**Решение.** Хелпер-паритет `HasDirectGrants::grantTtl(perm, panel, ?int $ttlSeconds)`.

### 🟠 20. `PanelResolver` не валидирует, что панель зарегистрирована 🔎
**Файл.** `src/Support/PanelResolver.php` (`resolve()`/`resolveDefault()` не проверяют `AzGuardManager::getPanels()`; проверяет только `resolveOrFail()`).
**Как использую.** `$user->hasPermission('app.view', 'nonexistent_panel')`.
**Проблема.** Незарегистрированная панель принимается без валидации → пермишн резолвится против неё, тихий неожиданный результат.
**Решение.** Валидация панели (исключение `PanelNotFoundException`) или warning в debug.

---

## Сводка по приоритету

- 🔴 **Высокий:** 1 (интерфейс актора), 2 (LSP `$entity`), 4 (panel-less проверка).
- 🟠 **Средний:** 3 (`isSuperAdmin`), 5 (`branch-alias`), 6 (тихий `hasPermissionIn`), 9, 10, 11, 12, 13, 14, 15, 20.
- 🟢 **Низкий:** 7 (testing-kit), 8 (id/morph one-liner), 16, 17, 19; 18 (⚠️ под вопросом).

## Быстрые и не ломающие (можно взять первыми)
1 (интерфейс), 3 (`isSuperAdmin`), 5 (`branch-alias`), 6 (наблюдаемость context-guard), 7 (testing-kit), 9, 11, 12, 13 (валидация morphType), 17.

## Хорошо спроектировано (проверено, вопросов нет)
`MorphColumns` (централизация морфов), per-request кэш (Octane-safe), graceful-skip падающих grant-source'ов (кроме `fail_on_source_exception`), `Authorizer`↔Gate, композиция `HasAzGuard`, опциональный биндинг `ContextGuard`.
