# Тестирование

AzGuard спроектирован для комфортного тестирования. Роли — это PHP-классы, они
всегда доступны без сидинга. Проверка прав — чистая функция, её легко
проверять в assert'ах.

## Настройка

Для feature-тестов, работающих с БД, используйте `RefreshDatabase` и
зарегистрируйте сервис-провайдер:

```php
use AzGuard\AzGuardServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AzGuardServiceProvider::class];
    }
}
```

## Пользователи с ролями

```php
public function test_editor_can_view_documents(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertOk();
}

public function test_viewer_cannot_delete(): void
{
    $user     = User::factory()->create();
    $document = Document::factory()->create();
    $user->assignRole('viewer');

    $this->actingAs($user)
        ->delete(route('documents.destroy', $document))
        ->assertForbidden();
}
```

## Прямая проверка прав

```php
public function test_editor_permissions(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->assertTrue($user->hasPermission(DocumentsPermission::View));
    $this->assertTrue($user->hasPermission(DocumentsPermission::Edit));
    $this->assertFalse($user->hasPermission(DocumentsPermission::Delete));

    // Проверяйте каждое право отдельно
    $this->assertTrue($user->hasPermission(DocumentsPermission::Edit));
    $this->assertFalse($user->hasPermission(DocumentsPermission::Delete)); // у editor нет Delete
}
```

## Тестирование прямых грантов

```php
use AzGuard\Facades\AzGuard;

public function test_user_with_direct_grant_can_access(): void
{
    $user = User::factory()->create();

    AzGuard::forUser($user)
        ->on('app')
        ->grant(DocumentsPermission::View);

    $this->assertTrue($user->hasPermission(DocumentsPermission::View));
}

public function test_expired_grant_is_denied(): void
{
    $user = User::factory()->create();

    // Передаём явную дату истечения в прошлом
    $user->grant(DocumentsPermission::View, 'app', now()->subMinute()); // уже истёк

    $user->flushPermissions();         // сбросить in-memory кеш

    $this->assertFalse($user->hasPermission(DocumentsPermission::View));
}

public function test_grant_with_ttl_is_active(): void
{
    $user = User::factory()->create();

    AzGuard::forUser($user)
        ->on('app')
        ->ttl(3600)                    // 1 час от текущего момента, в секундах
        ->grant(DocumentsPermission::Export);

    $this->assertTrue($user->hasPermission(DocumentsPermission::Export));
}
```

## Использование Gate в тестах

```php
public function test_gate_allows_editor(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->actingAs($user);

    // ✅ Gate использует полный ключ права с префиксом панели
    $this->assertTrue(Gate::allows('app.documents.view'));
    $this->assertTrue(Gate::allows('app.documents.edit'));
    $this->assertFalse(Gate::allows('app.documents.delete'));
}

public function test_gate_with_model(): void
{
    $user     = User::factory()->create();
    $document = Document::factory()->create(['owner_id' => $user->id]);
    $user->assignRole('editor');

    $this->actingAs($user);

    // Проходит через DocumentPolicy::update(), если она зарегистрирована
    $this->assertTrue(Gate::allows('app.documents.edit', $document));
}
```

## Тестирование сервисов, проверяющих права

У AzGuard нет fake/mock-слоя — тестируйте против реального резолвера. Держите
кеш-стор `'array'` (значение по умолчанию), чтобы ничего не «протекало» между
тестами, затем назначайте роли или гранты и проверяйте через
`hasPermission()` / `Gate::allows()`:

```php
public function test_service_checks_permission(): void
{
    $user = User::factory()->create();

    // Реальный грант — cache store='array' держит его в рамках запроса
    $user->grant(DocumentsPermission::View, 'app');

    $result = app(DocumentService::class)->canView($user, $document);

    $this->assertTrue($result);
}

public function test_service_denies_without_permission(): void
{
    $user = User::factory()->create();
    // Никаких прав не выдано

    $this->assertFalse(
        app(DocumentService::class)->canView($user, $document)
    );
}
```

## Синтаксис Pest

```php
it('allows editors to view documents', function () {
    $user = User::factory()->create();
    $user->assignRole('editor');

    expect($user->hasPermission(DocumentsPermission::View))->toBeTrue();
    expect($user->hasPermission(DocumentsPermission::Delete))->toBeFalse();
});

it('forbids unauthenticated access', function () {
    $this->get(route('documents.index'))
        ->assertRedirect(route('login'));
});

it('returns 403 for users without permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertForbidden();
});
```

## Фабрики пользователей с ролями

Определяйте состояния фабрики, чтобы тесты оставались читаемыми:

```php
// database/factories/UserFactory.php
public function editor(): static
{
    return $this->afterCreating(fn (User $user) =>
        $user->assignRole('editor')
    );
}

public function admin(): static
{
    return $this->afterCreating(fn (User $user) =>
        $user->assignRole('super-admin')
    );
}
```

```php
// Чистый setup для тестов
$editor = User::factory()->editor()->create();
$admin  = User::factory()->admin()->create();
```

## Проверка отказов в доступе

```php
// Неаутентифицирован — редирект на login
$this->get(route('documents.index'))
    ->assertRedirect(route('login'));

// Аутентифицирован, но без права — 403
$this->actingAs(User::factory()->create())
    ->get(route('documents.index'))
    ->assertForbidden();

// Правильная роль — проходит
$this->actingAs(User::factory()->editor()->create())
    ->get(route('documents.index'))
    ->assertOk();
```

## Советы

- **Сбрасывайте кеш прав между сменами состояния** в рамках одного теста: `$user->flushPermissions()`.
- **Используйте `assertForbidden()`, а не `assertStatus(403)`** — читаемее вывод теста.
- **Тестируйте обе стороны.** Для каждого права, которое вы проверяете как `true`, протестируйте и то, что видит пользователь *без* него.
- **Держите array-стор кеша в тестах** — оставьте `cache.store` равным `'array'` (значение по умолчанию) в `config/az-guard.php`, чтобы резолвленные права никогда не «протекали» между запросами/тестами.
