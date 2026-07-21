<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\Models;

use AzGuard\Concerns\InteractsWithAzScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Entity-scoped роли: роль на конкретный экземпляр модели —
 * пользователь editor в Project A, но без роли в Project B.
 * Накладывается ПОВЕРХ глобальных ролей, не заменяет их.
 *
 * Use cases: multi-tenant проекты, team-admin на Team,
 * reviewer на Document, owner на любую Eloquent-модель.
 *
 * Трейт вешается на сущность-скоуп; User уже использует HasAzGuard.
 */
class Project extends Model
{
    use InteractsWithAzScopes;
}

// ── Назначение / снятие / проверка scoped-роли ──────────────────────────────
//
// use App\AzGuard\App\Roles\EditorRole;
//
// $user->assignScopedRole(EditorRole::class, $project);
// $user->removeScopedRole(EditorRole::class, $project);
// $user->hasScopedRole(EditorRole::class, $project);      // bool
//
// ── Проверка scoped-права ───────────────────────────────────────────────────
//
// Порядок резолва hasScopedPermission():
//   1. Wildcard — глобальная роль с ['*'] → сразу true.
//   2. Глобальные роли (assignRole) — проверяются первыми.
//   3. Scoped-роли (assignScopedRole) — для переданной сущности.
//
// use AzGuard\Facades\AzGuard;
// use App\AzGuard\App\Permissions\DocumentsPermission;
//
// if ($user->hasScopedPermission(
//     AzGuard::permission('app', DocumentsPermission::Edit),  // 'app.documents.edit'
//     $project,
// )) {
//     // пользователь может редактировать именно этот проект
// }
//
// Gate использует scoped-резолв автоматически, когда вторым аргументом
// передана сущность:
//
// Gate::allows('app.documents.edit', $project);
//
// ── Кеш ─────────────────────────────────────────────────────────────────────
//
// Кеш scoped-прав сбрасывается автоматически при assignScopedRole() /
// removeScopedRole(). Ручной сброс для пользователя:
//
// php artisan azguard:cache-reset --user=42
