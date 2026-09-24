<!-- Source: anonymized production Laravel project -->
# Pest-тестирование

## Сначала прочитай

1. **`testing-rules`** — изоляция БД `{{project_name}}_test`, Docker vs локальный PHP, Dusk, запрет опасных команд вне testing.
2. Тип теста: для HTTP, API и сценариев доступа — Feature (`tests/Feature/`); для доменной модели и Actions — часто `tests/Feature/Document/`; чистая логика без БД — Unit (`tests/Unit/`).

## Сценарий работы агента

1. Pre-flight: контейнеры и БД для тестов — см. **`testing-rules`** и cursor rule test-preflight (Docker `docker compose ps`, затем запуск из того же окружения, что и тесты).
2. Открыть изменённые классы и при необходимости фабрики.
3. Запуск только нужных тестов с фильтром: `php artisan test --filter=...` или через Docker из таблицы в **`testing-rules`**.

## Ожидания проекта

- По умолчанию Feature-тесты, если нет явной причины ограничиться Unit.
- Данные через фабрики моделей.
- Утверждения про поведение (HTTP-код, редирект, состояние БД, уведомления), а не про внутреннюю реализацию.

## Команды

Сначала определи окружение (**`testing-rules`**): при **`DB_HOST=pgsql`** выполняй команды в контейнере **`app`**.

**Локальный PHP:**

```bash
php artisan test
./vendor/bin/pest
```

**Docker:**

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pest
```

Тесты всегда используют БД **`{{project_name}}_test`** (`phpunit.xml`), не основную из `.env`.
