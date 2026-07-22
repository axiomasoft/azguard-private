<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\AzGuard\App\Roles;

use App\AzGuard\App\Permissions\DocumentsPermission;
use AzGuard\Contracts\RoleInterface;

/**
 * Статическая роль — PHP-класс, источник истины в коде (git, PR-ревью).
 * Регистрируется в PanelProvider через ->roleClasses([...]).
 *
 * Конвенция уровней: viewer=1, editor=10, manager=50, admin=100, super-admin=999.
 * Уровни НЕ наследуют права — manager не получает права editor автоматически,
 * перечисляй явно. Уровни нужны для сравнений: $user->hasRoleLevel('>= 50').
 */
class EditorRole implements RoleInterface
{
    public function getName(): string
    {
        return 'editor';
    }

    public function getLevel(): int
    {
        return 10;
    }

    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Edit,
        ];
    }
}

/**
 * Super-admin: wildcard '*' — Gate::before вернёт true, не вызывая политику.
 */
class SuperAdminRole implements RoleInterface
{
    public function getName(): string
    {
        return 'super-admin';
    }

    public function getLevel(): int
    {
        return 999;
    }

    public function permissions(): array
    {
        return ['*'];
    }
}

// ── Назначение и проверка ролей (User использует трейт HasAzGuard) ──────────
//
// $user->assignRole(EditorRole::class);            // по классу — предпочтительно
// $user->assignRole('editor');                     // по имени
// $user->assignRole('admin', panel: 'admin');      // явная панель при коллизии имён
// $user->syncRoles([EditorRole::class]);           // заменяет ВЕСЬ список ролей
// $user->syncRoles([]);                            // снимает все роли
// $user->removeRole(EditorRole::class);
//
// $user->hasRole('editor');                        // bool
// $user->hasAnyRole(['editor', 'admin']);          // хотя бы одна
// $user->hasAllRoles(['editor', 'moderator']);     // все сразу
// $user->hasRoleLevel('>= 50');                    // сравнение по уровню
// $user->getRoleLevel();                           // int: максимальный уровень
//
// ── Query scopes ────────────────────────────────────────────────────────────
//
// User::role('editor')->where('active', true)->paginate();
// User::withoutRole('editor')->get();
// User::permission(DocumentsPermission::Edit)->get();
// User::with('azRoles')->paginate();               // eager-load против N+1
//
// ── Синхронизация статических ролей в БД (для Filament-дропдаунов) ─────────
//
// php artisan azguard:sync-roles --panel=app       // безопасно в CI/CD
