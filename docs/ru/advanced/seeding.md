# Сидирование базы данных

## Базовый сидер

```php
// database/seeders/RolesAndPermissionsSeeder.php
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Синхронизируем классы ролей с БД (зеркалит классы ролей в таблицу roles перед назначением)
        Artisan::call('guard:sync-roles');

        // Назначаем роли конкретным пользователям — по классу (предпочтительно)
        $admin = User::where('email', 'admin@example.com')->first();
        $admin?->assignRole(AdminRole::class);        // или 'app:admin' после sync

        $editor = User::where('email', 'editor@example.com')->first();
        $editor?->assignRole(EditorRole::class);      // или 'app:editor' после sync
    }
}
```

## Сидирование в тестах

```php
public function test_seeder_assigns_correct_roles(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::where('email', 'admin@example.com')->first();
    $this->assertTrue($admin->hasRole(AdminRole::class));   // 'admin' по имени тоже работает
    $this->assertTrue($admin->hasPermission(UsersPermission::Manage));
}
```

## Фабрики с ролями

```php
// database/factories/UserFactory.php
public function editor(): static
{
    return $this->afterCreating(function (User $user) {
        $user->assignRole(EditorRole::class);     // 'editor' по имени тоже работает
    });
}

// В тесте
$editor = User::factory()->editor()->create();
```
