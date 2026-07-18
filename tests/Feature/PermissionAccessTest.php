<?php

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

// Фейковый класс роли для теста (реализует контракт через BaseRole).
class FakeAdminRole extends BaseRole
{
    public function permissions(): array
    {
        return ['admin.post.view', 'admin.post.edit'];
    }
}

class FakePost {}

// Создаем "фейковую" политику
class FakePostPolicy
{
    public function view($user)
    {
        return $user->hasPermission('admin.post.view', 'admin');
    }
}

it('grants access when user has a role with the required permission', function () {
    Gate::policy(FakePost::class, FakePostPolicy::class);

    // 2. Создаем роль в БД и указываем путь к классу логики
    $role = createRoleWithClass(['name' => 'Administrator',
    ], FakeAdminRole::class);

    // 3. Создаем пользователя и привязываем роль (используя твой трейт HasAzGuard)
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    $user->roles()->attach($role);

    // 4. Проверка: HasAzGuard должен залезть в FakeAdminRole и найти там разрешение
    expect($user->hasPermission('admin.post.view', 'admin'))->toBeTrue();
    expect($user->hasPermission('admin.post.delete', 'admin'))->toBeFalse();

    $this->actingAs($user);

    expect($user->can('view', new FakePost))->toBeTrue();
});
