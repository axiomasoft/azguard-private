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

Persisted-имя панельной code role — `{panelId}:{getName()}`; встроенный
`super-admin` без префикса. `--dry-run` показывает те же решения create/rename/
collision. Collision (включая DB-only на каноническом имени) останавливает запись.

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

Сдвигает глобальную permission-state revision и локальный request-кэш.
Настроенный cache store **не** flush-ится — чужие ключи в том же store
остаются. Используйте после неофициальных bulk-SQL или смены
`cache.generation`.

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

## Скаффолд панелей и доменов

`--actor` принимает существующий класс `Authenticatable`. По умолчанию берётся
модель провайдера активного auth guard. Для add-domain модель можно задать через
`az-guard.scaffold.domain_models.{panelId}.{domain_key}`. Конфликт перечисляет
целевые файлы; `--force` меняет только их. Для custom provider/config команда
выводит ручной шаг регистрации.


```bash
php artisan make:guard-panel Admin Documents --model=App\\Models\\Document
php artisan make:guard-domain Admin Invoices --model=App\\Models\\Invoice
```

`make:guard-panel` — новая панель; `make:guard-domain` — домен в существующей.
Без `--model` у panel-команды остаётся legacy `App\\Models\\{Domain}` с
предупреждением. Повтор с теми же аргументами не меняет файлы; конфликт —
только с `--force` на целевых generated-файлах.

## `guard:list-permissions`

```bash
php artisan guard:list-permissions
php artisan guard:list-permissions app
```

Выводит таблицу всех зарегистрированных прав по панелям.
