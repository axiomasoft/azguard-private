<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\AzGuard\App\Permissions;

use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\RoleOnly;

/**
 * Permissions — это PHP enum cases, не записи в БД. Один enum на ресурс
 * (DocumentsPermission, UsersPermission), не один гигантский AppPermission.
 *
 * Полный ключ: {panel}.{resource}.{action} — префикс панели ('app.')
 * добавляет AzGuard по месту регистрации enum'а в PanelProvider,
 * внутри enum'а объявляется только 'documents.view'.
 *
 * Атрибуты:
 *   #[GateAbility] — регистрируется в Laravel Gate как '{panel}.{value}'
 *                    (доступно в Gate::allows, @can, политиках); это default,
 *                    но указывай явно — намерение видно на ревью.
 *   #[RoleOnly]    — есть в каталоге, но в Gate НЕ регистрируется:
 *                    проверяется только через $user->hasPermission();
 *                    в политике или @can всегда вернёт false.
 */
enum DocumentsPermission: string
{
    #[GateAbility]
    case View = 'documents.view';

    #[GateAbility]
    case Create = 'documents.create';

    #[GateAbility]
    case Edit = 'documents.edit';

    #[GateAbility]
    case Export = 'documents.export';

    // Внутренняя проверка, не экспонируется через Gate
    #[RoleOnly]
    case Purge = 'documents.purge';
}

// ── Проверки прав ───────────────────────────────────────────────────────────
//
// Всегда enum-кейсы, никогда сырые строки: опечатка в строке
// (Gate::allows('app.documents.veiw')) молча вернёт false — незаметная дыра.
//
// На модели (HasAzGuard):
//   $user->hasPermission(DocumentsPermission::View);
//   $user->hasPermission('app.documents.view');         // полный строковый ключ тоже работает
//   $user->hasAnyPermission([DocumentsPermission::Edit, DocumentsPermission::Export]);
//   $user->hasAllPermissions([DocumentsPermission::View, DocumentsPermission::Edit]);
//
// Через Gate (нужен #[GateAbility]; Gate принимает BackedEnum напрямую):
//   Gate::allows(DocumentsPermission::View);             // bool
//   Gate::allows(DocumentsPermission::Edit, $document);  // через политику
//   $this->authorize(DocumentsPermission::View);         // 403 при отказе
//
// Middleware — единственное место, где строка неизбежна:
//   Route::get('/documents', DocumentController::class)
//       ->middleware('can:' . DocumentsPermission::View->value);
//
// Blade (нет use-statements — FQCN + ->value, либо передавай готовые bool):
//   @can(\App\AzGuard\App\Permissions\DocumentsPermission::Edit->value) ... @endcan
//
// Интроспекция:
//   $user->getAllPermissions();        // роли + direct grants, Collection<string>
//   $user->getPermissionsViaRoles();   // только из ролей
//   $user->getDirectPermissions();     // только прямые гранты
//
// ── Artisan ────────────────────────────────────────────────────────────────
//
// php artisan azguard:make-permission App DocumentsPermission
// php artisan azguard:list-permissions --panel=app --with-roles
