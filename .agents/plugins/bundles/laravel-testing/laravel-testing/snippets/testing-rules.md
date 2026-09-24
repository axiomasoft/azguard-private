<!-- Source: anonymized production Laravel project -->
# Testing Rules

## Перед началом задачи: Docker или локальный PHP

**Не используйте `DB_CONNECTION` для выбора режима:** и в Docker, и без него обычно **`pgsql`** (или `mysql`).

**Признаки работы через Docker Compose** (типичный dev-стек):
- **`DB_HOST=pgsql`** — имя сервиса базы данных из `docker-compose.yml` (не резолвится с хоста как БД).
- Дополнительно часто **`REDIS_HOST=redis`**.

**Признаки локального PHP без Docker** (БД на машине):
- **`DB_HOST=127.0.0.1`**, **`localhost`** или другой **IP/hostname** вашей машины.

**Команды:**

| Действие | Docker | Без Docker |
|----------|--------|------------|
| Artisan, Composer | `docker compose exec app php artisan …` | `php artisan …` |
| Тесты Pest/PHPUnit | `docker compose exec app php artisan test` | `php artisan test` или `vendor/bin/pest` |
| pnpm / Vite | `docker compose exec vite pnpm …` | `pnpm …` |

При сомнении: **`docker compose ps`** — если контейнеры запущены, ориентируйтесь на работу через Docker.

**Основная БД приложения** (`DB_DATABASE` в `.env`) при прогоне тестов **не должна** использоваться: см. раздел ниже и `tests/TestCase.php`.

## Database Safety

- **Никогда** не направляйте Pest/PHPUnit на основную базу из `.env`. `RefreshDatabase` и Dusk делают **`migrate:fresh`** на тестовой БД.
- **Критично для агента:** запрещено выполнять `migrate:fresh` / `db:wipe` без явного тестового окружения. Разрешён только вариант с `--env=testing`.
- Тесты используют только БД **`{{project_name}}_test`**: это задано в `phpunit.xml` и `tests/bootstrap.php`.

### Изоляция БД: дыры вне PHPUnit/Pest (обязательно для агента)

`TestEnvironmentGuard` срабатывает только при загрузке приложения из **`tests/TestCase`** / **`DuskTestCase`** в режиме `test`. Любая команда, которая поднимает Laravel из **обычного `.env`**, пишет в **основную БД**, если явно не переопределить окружение.

**Запрещено без явного перевода на тестовую БД (`APP_ENV=testing`):**

| Действие | Почему опасно |
|----------|----------------|
| `php artisan tinker` / однострочный `tinker --execute` | Фабрики, `create()`, сиды — мутируют основную БД |
| `php artisan db:seed`, `migrate`, `migrate:fresh`, `db:wipe` | Прямая мутация схемы/данных |
| MCP / инструменты **database-query** с INSERT/UPDATE/DELETE | Целевая БД задаётся конфигом приложения |
| **Dusk** с `DUSK_ENV_MODE=current` | Guard и `migrate:fresh` отключены |

**Разрешённые паттерны:**

1. Отладка домена — **добавить временный интеграционный тест** и вызвать `php artisan test …` (предпочтительно).
2. Если без REPL нельзя — только с переопределением окружения на тестовое:
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE={{project_name}}_test app php artisan tinker
```
3. **Чтение** из основной БД (SELECT, MCP read-only) для диагностики — допустимо только по явному запросу пользователя.

## Test Types

### Feature Tests (`tests/Feature/`)
- HTTP request/response, API endpoints, auth flows, notifications.
- Use `RefreshDatabase`, `actingAs()`, `postJson()`.
- Broadcast: `Event::fake()` per test, then `Event::assertDispatched()`.

### Unit Tests (`tests/Unit/`)
- Pure PHP logic, services, repositories, formatters. No DB or HTTP.

### Browser Tests (`tests/Browser/`) — Laravel Dusk
- Full end-to-end with Chromium. Extend `Tests\DuskTestCase` (not `TestCase`).
- **Do NOT use `RefreshDatabase`** — `DuskTestCase::setUp()` runs `migrate:fresh --seed` automatically.
- Run (хост, Pest): `php artisan pest:dusk` (не `artisan dusk`).
- Конфиг: `phpunit.dusk.xml`; при прогоне команда подменяет `.env` содержимым `.env.dusk.local`.
- Debug (headful): `php artisan pest:dusk --browse`.

## Conventions

- All tests use Pest syntax.
- Run: `php artisan test` / `docker compose exec app php artisan test`.
- Filter: `php artisan test --filter=TestName`.
- Проверки Node/pnpm вынесены в отдельный skill: `.ai/skills/node-pnpm-preflight/SKILL.md`.
- Write tests for every code change.

## Feature Test Template

```php
uses(RefreshDatabase::class);

it('allows authorized role to perform action', function () {
    // 1. Arrange
    $user = User::factory()->withRole(UserRole::Admin)->create();
    $resource = {{Model}}::factory()->create();

    // 2. Act
    $this->actingAs($user)
        ->postJson(route('{{resource}}.action', $resource))
        ->assertOk();

    // 3. Assert
    expect($resource->fresh()->status)->toBe({{Model}}Status::Completed);
});
```

## Browser Test Template

```php
use App\Models\User\User;
use Laravel\Dusk\Browser;

test('user can interact with UI', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/login')
            ->type('email', $user->email)
            ->type('password', 'password')
            ->press('Submit')
            ->assertPathIs('/dashboard');
    });
});
```
