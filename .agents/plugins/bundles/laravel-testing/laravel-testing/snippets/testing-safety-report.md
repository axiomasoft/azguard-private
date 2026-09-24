<!-- Source: anonymized production Laravel project -->
# Безопасность тестовой среды в {{project_name}}

## Как устроена изоляция

В {{project_name}} тесты используют отдельную PostgreSQL базу **`{{project_name}}_test`**. Основная рабочая база задаётся в `.env` как **`DB_DATABASE`** (часто `{{project_name}}`).

- `phpunit.xml` и `tests/bootstrap.php` принудительно задают **`DB_DATABASE={{project_name}}_test`** (и `APP_ENV=testing`).
- **`DB_HOST` / порт / пользователь / пароль** наследуются из окружения: в Docker это тот же хост **`pgsql`**, что и у приложения; локально — **`127.0.0.1`** и значения из `.env`.
- `RefreshDatabase` запускает `migrate:fresh` **только** на подключении с базой **`{{project_name}}_test`**.
- Рабочая база (`{{project_name}}` и т.п.) **не должна** использоваться тестами; при нарушении падает `tests/TestCase::assertIsolatedTestDatabase`.

## Две базы в Docker

В контейнере Postgres создаются **`DB_DATABASE`** (из `.env`) и **`{{project_name}}_test`** (init-скрипт `docker/postgres/init/01-{{project_name}}-test.sh` при **первом** создании тома). Старый том без `{{project_name}}_test`: см. `docs/docker/development.md` или `docs/docker/troubleshooting.md`.

## Проверка перед запуском

**Локально (Postgres на хосте):**

```bash
# Убедиться, что тестовая БД существует (подставьте пользователя/порт из .env)
psql -h 127.0.0.1 -p 5432 -U {{project_name}} -d postgres -c "\l" | grep {{project_name}}_test
```

**Docker:**

```bash
docker compose exec pgsql psql -U {{project_name}} -d postgres -c "\l" | grep {{project_name}}_test
```

Если нет — создать:

```bash
# Docker (из корня репозитория, контейнер pgsql запущен)
make db-create-test
# или: bash docker/postgres/create-{{project_name}}-test-db.sh

# Хост (Postgres без Docker)
psql -h 127.0.0.1 -U {{project_name}} -d postgres -c 'CREATE DATABASE {{project_name}}_test OWNER {{project_name}};'
```

## Запуск тестов

**Хост:**

```bash
php artisan test
php artisan test tests/Feature/Document/
php artisan test --filter=DocumentActionsTest
php artisan dusk
```

**Docker:**

```bash
docker compose exec app php artisan test
docker compose exec app php artisan test tests/Feature/Document/
docker compose exec app php artisan test --filter=DocumentActionsTest
# Dusk — если настроен в образе:
docker compose exec app php artisan dusk
```

## Что НЕЛЬЗЯ делать

- Менять `phpunit.xml` так, чтобы тесты ходили в основную `DB_DATABASE` из рабочего `.env`.
- Убирать проверку `assertIsolatedTestDatabase` без замены другой гарантией.
- Запускать `migrate:fresh` вручную против рабочей БД без явного намерения (тесты делают fresh только на `{{project_name}}_test`).
- Запускать **`tinker`**, **`db:seed`**, MCP с мутациями без явного перевода на **`{{project_name}}_test`** — см. **`testing-rules`**, раздел «Изоляция БД: дыры вне PHPUnit/Pest».

## Режим Docker vs хост

См. навык **`testing-rules`**: ориентир **`DB_HOST=pgsql`** (Docker) vs **`127.0.0.1`** / IP (локально).
