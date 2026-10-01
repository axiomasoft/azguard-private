<!-- Source: anonymized production Laravel project -->
# Testing Rules

## Before starting the task: Docker or local PHP

**Do not use `DB_CONNECTION` to select mode:** and in Docker, and without it usually **`pgsql`** (or `mysql`).

**Signs of operation through Docker Compose** (typical dev-stack):
- **`DB_HOST=pgsql`** — database service name from `docker-compose.yml` (does not resolve from the host as a database).
- Additionally often **`REDIS_HOST=redis`**.

**Signs of local PHP without Docker** (DB on the machine):
- **`DB_HOST=127.0.0.1`**, **`localhost`** or other **IP/hostname** of your machine.

**Commands:**

| Action | Docker | Without Docker |
|----------|--------|------------|
| Artisan, Composer | `docker compose exec app php artisan …` | `php artisan …` |
| Tests Pest/PHPUnit | `docker compose exec app php artisan test` | `php artisan test` or `vendor/bin/pest` |
| pnpm / Vite | `docker compose exec vite pnpm …` | `pnpm …` |

When in doubt: **`docker compose ps`** — if containers are running, focus on working through Docker.

**Main application database** (`DB_DATABASE` in `.env`) when running tests **should not** used: see section below and `tests/TestCase.php`.

## Database Safety

- **Never** do not send Pest/PHPUnit to the main database from `.env`. `RefreshDatabase` and Dusk do **`migrate:fresh`** on the test database.
- **Agent critical:** not allowed to execute `migrate:fresh` / `db:wipe` without an explicit test environment. Only option with `--env=testing`.
- Tests use only the database **`{{project_name}}_test`**: this is set in `phpunit.xml` and `tests/bootstrap.php`.

### DB isolation: holes out PHPUnit/Pest (required for agent)

`TestEnvironmentGuard` only fires when the application is loaded from **`tests/TestCase`** / **`DuskTestCase`** in mode `test`. Any command that raises Laravel from **regular `.env`**, writes in **main database**, unless you explicitly override the environment.

**Prohibited without explicit transfer to the test database (`APP_ENV=testing`):**

| Action | Why it's dangerous |
|----------|----------------|
| `php artisan tinker` / one-liner `tinker --execute` | Factories, `create()`, seeds - mutate the main database |
| `php artisan db:seed`, `migrate`, `migrate:fresh`, `db:wipe` | Direct mutation of the circuit/data |
| MCP / tools **database-query** with INSERT/UPDATE/DELETE | The target database is specified by the application config |
| **Dusk** with `DUSK_ENV_MODE=current` | Guard and `migrate:fresh` disabled |

**Allowed patterns:**

1. Debugging domain - **add temporary integration test** and call `php artisan test …` (preferred).
2. If without REPL is not possible - only with redefining the environment to test:
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE={{project_name}}_test app php artisan tinker
```
3. **Read** from the main database (SELECT, MCP read-only) for diagnostics - only allowed upon explicit user request.

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
- Run (host, Pest): `php artisan pest:dusk` (not `artisan dusk`).
- Config: `phpunit.dusk.xml`; when running, the command replaces `.env` content `.env.dusk.local`.
- Debug (headful): `php artisan pest:dusk --browse`.

## Conventions

- All tests use Pest syntax.
- Run: `php artisan test` / `docker compose exec app php artisan test`.
- Filter: `php artisan test --filter=TestName`.
- Checks Node/pnpm are moved to a separate skill: `.ai/skills/node-pnpm-preflight/SKILL.md`.
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
