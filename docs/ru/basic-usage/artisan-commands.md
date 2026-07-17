# Artisan-команды

AzGuard предоставляет runtime-команды под префиксом `guard:` (включая
подгруппы `guard:context:*`, `guard:catalog:*` и `guard:filament:*`) и
генераторы-скаффолды под префиксом `make:guard-`. Других префиксов в пакете
нет — это проверяется архитектурным тестом
(`tests/Feature/CommandPrefixRegistrationTest.php`).

Полный и всегда актуальный список команд — см. англоязычную версию этой
страницы: [Artisan Commands](/basic-usage/artisan-commands). Ниже — самые
частые команды.

## `guard:install`

Публикует конфиг и прогоняет миграции одной командой:

```bash
php artisan guard:install
```

## `guard:sync-roles`

Синхронизирует PHP-классы ролей с таблицей `roles` в БД.

```bash
php artisan guard:sync-roles
php artisan guard:sync-roles --panel=app
php artisan guard:sync-roles --dry-run
```

Запускайте при деплое или в CI/CD — команда **не** назначает роли
пользователям, только гарантирует наличие записи роли для UI.

## `guard:doctor`

Проверяет и сообщает о проблемах конфигурации:

```bash
php artisan guard:doctor
php artisan guard:doctor --panel=app
php artisan guard:doctor --json
```

Что проверяет:

- Массив `panels` в конфиге не пуст
- Все зарегистрированные классы панелей существуют
- Необходимые миграции применены
- Каждый зарегистрированный enum прав — валидный backed enum
- Каждый класс роли реализует `RoleInterface`
- Нет дублирующихся строковых прав между enum'ами
- У каждого метода с `#[GateAbility]` есть соответствующий класс политики
- Хранилище кэша доступно

## `guard:cache-reset`

```bash
php artisan guard:cache-reset

# Без запроса подтверждения
php artisan guard:cache-reset --force
```

## `guard:grant` / `guard:grants` / `guard:revoke-grant` / `guard:prune-grants`

```bash
# Выдать грант (опционально с TTL в секундах)
php artisan guard:grant 42 app.documents.export app --ttl=3600

# Список активных грантов
php artisan guard:grants
php artisan guard:grants --user=42 --panel=app

# Отозвать грант
php artisan guard:revoke-grant 42 app.documents.export app

# Удалить истёкшие гранты
php artisan guard:prune-grants
```

Добавьте прунинг в расписание:

```php
$schedule->command('guard:prune-grants')->daily();
```

## `guard:list-permissions`

```bash
php artisan guard:list-permissions
php artisan guard:list-permissions app
```

Выводит таблицу всех зарегистрированных прав по панелям.
