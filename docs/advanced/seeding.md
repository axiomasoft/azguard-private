# Database Seeding

This guide covers how to seed roles and permissions — both static (code-defined) roles and dynamic (DB-backed) roles.

## Static roles don't need seeding

Roles defined as PHP classes are **always available** without seeding. You can assign them to users immediately after running migrations:

```php
// In a seeder, factory, or anywhere after `php artisan migrate`
$user->assignRole(EditorRole::class);  // by class (preferred); 'app:editor' after sync
```

Run `php artisan guard:sync-roles` first so your code role classes are mirrored into the `roles` table before assigning.

## Seeding dynamic (DB-backed) roles

For runtime-configurable roles, create `Role` records and attach permissions as
`RolePermission` rows (full, panel-prefixed `permission_key` + `panel_id`):

```php
// database/seeders/RolesSeeder.php
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $editor = Role::firstOrCreate(['name' => 'editor'], ['level' => 10]);
        $this->addPermissions($editor, 'app', [
            'app.documents.view',
            'app.documents.create',
            'app.documents.edit',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer'], ['level' => 1]);
        $this->addPermissions($viewer, 'app', [
            'app.documents.view',
        ]);

        $manager = Role::firstOrCreate(['name' => 'manager'], ['level' => 50]);
        $this->addPermissions($manager, 'app', [
            'app.documents.view',
            'app.documents.create',
            'app.documents.edit',
            'app.documents.delete',
            'app.reports.view',
            'app.reports.export',
        ]);
    }

    private function addPermissions(Role $role, string $panelId, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::firstOrCreate([
                'role_id'        => $role->id,
                'permission_key' => $key,
                'panel_id'       => $panelId,
            ]);
        }
    }
}
```

Register in `DatabaseSeeder`:

```php
public function run(): void
{
    $this->call([
        RolesSeeder::class,
        UsersSeeder::class,
    ]);
}
```

## Seeding users with roles

```php
// database/seeders/UsersSeeder.php
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password')]
        );
        $admin->assignRole(SuperAdminRole::class);    // 'super-admin' by name also works

        $editor = User::firstOrCreate(
            ['email' => 'editor@example.com'],
            ['name' => 'Editor User', 'password' => Hash::make('password')]
        );
        $editor->assignRole(EditorRole::class);

        $viewer = User::firstOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Viewer User', 'password' => Hash::make('password')]
        );
        $viewer->assignRole(ViewerRole::class);
    }
}
```

## Seeding with factories

```php
// database/factories/UserFactory.php
public function editor(): static
{
    return $this->afterCreating(fn (User $u) =>
        $u->assignRole(EditorRole::class)
    );
}

public function manager(): static
{
    return $this->afterCreating(fn (User $u) =>
        $u->assignRole(ManagerRole::class)
    );
}

public function admin(): static
{
    return $this->afterCreating(fn (User $u) =>
        $u->assignRole(SuperAdminRole::class)
    );
}
```

```php
// In tests or seeders
$editor  = User::factory()->editor()->create();
$manager = User::factory()->manager()->create();
$admin   = User::factory()->admin()->create();
```

## Idempotent seeders

Always use `firstOrCreate` / `updateOrCreate` so seeders can be re-run safely:

```php
// ✅ Idempotent — safe to run multiple times
$role = Role::firstOrCreate(
    ['name' => 'editor'],
    ['level' => 10]
);

// ❌ Creates duplicates on re-seed
$role = Role::create(['name' => 'editor', 'level' => 10]);
```

## Syncing static roles to the DB

After deploying new PHP role classes, sync them to the `roles` table so Filament dropdowns and API endpoints stay up to date:

```bash
# Sync all panels
php artisan guard:sync-roles

# Sync a specific panel
php artisan guard:sync-roles --panel=app
```

This command is safe to include in your CI/CD deploy pipeline.

## Running seeders in CI

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=RolesSeeder
```

For test environments that use `RefreshDatabase`, static roles are always available without seeding. Only seed if you specifically need dynamic roles or specific user fixtures.
