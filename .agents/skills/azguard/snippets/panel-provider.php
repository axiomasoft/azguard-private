<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\AzGuard\Panels;

use App\AzGuard\App\Permissions\DocumentsPermission;
use App\AzGuard\App\Permissions\UsersPermission;
use App\AzGuard\App\Roles\EditorRole;
use App\AzGuard\App\Roles\ViewerRole;
use AzGuard\PanelProvider;
use AzGuard\Support\Panel;

/**
 * Панель — изолированный namespace прав внутри одного приложения
 * (app — конечные пользователи, admin — персонал, api — клиенты API).
 *
 * Permission 'documents.view', зарегистрированный в панели 'app',
 * хранится и проверяется как 'app.documents.view' — префикс автоматический.
 *
 * При boot провайдер также рекурсивно сканирует каталоги Policies/
 * рядом с собой (файлы *Policy.php) и авторегистрирует политики
 * по атрибутам (см. policy-gateability.php).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->permissionEnums([
                DocumentsPermission::class,
                UsersPermission::class,
            ])
            ->roleClasses([
                EditorRole::class,
                ViewerRole::class,
            ]);
    }
}

// ── Регистрация в config/az-guard.php ──────────────────────────────────────
//
// 'panels' => [
//     \App\AzGuard\Panels\AppPanelProvider::class,
//     \App\AzGuard\Panels\AdminPanelProvider::class,
// ],
//
// ── Один пользователь — разные роли в разных панелях ───────────────────────
//
// $user->assignRole('editor', panel: 'app');
// $user->assignRole('viewer', panel: 'admin');
// $user->hasPermission('app.documents.edit');   // true
// $user->hasPermission('admin.users.delete');   // false
//
// ── Типовая структура проекта ──────────────────────────────────────────────
//
// app/AzGuard/
//   Panels/AppPanelProvider.php
//   App/
//     Permissions/DocumentsPermission.php
//     Roles/EditorRole.php
//   Admin/
//     Permissions/UsersPermission.php
//     Roles/OperatorRole.php
//
// ── Artisan ────────────────────────────────────────────────────────────────
//
// php artisan azguard:make-panel App
// php artisan azguard:doctor          // показывает все панели и их статус
