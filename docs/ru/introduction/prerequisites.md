# Требования

Перед установкой AzGuard убедитесь, что ваше окружение соответствует следующим требованиям.

## Программные зависимости

| Зависимость | Версия |
|---|---|
| PHP | ≥ 8.3 |
| Laravel | 11.x или 12.x |
| Composer | 2.x |

## Расширения PHP

AzGuard не требует нестандартных расширений сверх тех, что нужны Laravel:

- `pdo` + соответствующий драйвер БД (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`)
- `mbstring`
- `openssl`
- `tokenizer`
- `xml`

## База данных

AzGuard хранит **только** связки `user → role` и записи прямых грантов. Для каталога прав таблицы не нужны — они живут в PHP-коде.

Поддерживаемые СУБД:
- MySQL 8.0+
- PostgreSQL 13+
- SQLite 3.35+ (тесты / разработка)

## Laravel Octane / Kubernetes

AzGuard не хранит глобального состояния в статических свойствах или Singleton-объектах с изменяемым состоянием. Это делает его полностью совместимым с Laravel Octane (Swoole, RoadRunner) и горизонтально масштабируемыми Kubernetes-деплоями.

::: warning
Если вы используете кастомный кэш-драйвер для хранения разрешений — убедитесь, что он также stateless или корректно разделяет состояние между воркерами.
:::

## Поддерживаемые Guards

AzGuard работает с любым guard, зарегистрированным в `config/auth.php`. Наиболее распространённые:

```php
// config/auth.php
'guards' => [
    'web'   => ['driver' => 'session', 'provider' => 'users'],
    'api'   => ['driver' => 'sanctum',  'provider' => 'users'],
    'admin' => ['driver' => 'session', 'provider' => 'admins'],
],
```

Каждый guard может иметь собственный набор панелей AzGuard. Подробнее — в разделе [Несколько Guards](/ru/basic-usage/multiple-guards).

## Следующий шаг

→ [Установка](/ru/introduction/installation)
