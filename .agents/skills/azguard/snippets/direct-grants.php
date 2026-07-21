<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\Models;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasDirectGrants;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Direct Grants — механизм ИСКЛЮЧЕНИЙ, не основной паттерн.
 * Default: права → роли → пользователи. Прямой грант — когда одному
 * пользователю нужно временное/разовое право, не тянущее на роль.
 * Грантишь одно и то же 5+ пользователям — пора создавать роль.
 *
 * HasDirectGrants расширяет hasPermission(): теперь проверяются
 * роли И прямые гранты — других изменений в коде не нужно.
 */
class User extends Authenticatable
{
    use HasAzGuard;
    use HasDirectGrants;
}

// ── Выдача (fluent API) ─────────────────────────────────────────────────────
//
// use AzGuard\Facades\AzGuard;
// use App\AzGuard\App\Permissions\DocumentsPermission;
//
// // Бессрочно
// AzGuard::forUser($user)
//     ->on('app')
//     ->give(DocumentsPermission::Export);
//
// // С TTL 1 час
// AzGuard::forUser($user)
//     ->on('app')
//     ->ttl(3600)
//     ->give(DocumentsPermission::Export);
//
// // Шорткат
// AzGuard::grantDirect($user, DocumentsPermission::Export, 'app', ttl: 3600);
//
// give() идемпотентен: повторный вызов обновляет expires_at без дублей.
//
// ── Отзыв ───────────────────────────────────────────────────────────────────
//
// AzGuard::forUser($user)->on('app')->revoke(DocumentsPermission::Export);
// AzGuard::revokeDirect($user, DocumentsPermission::Export, 'app');
// AzGuard::forUser($user)->on('app')->revokeAll();   // все гранты панели
//
// ── Проверка / список ───────────────────────────────────────────────────────
//
// $user->hasDirectGrant(DocumentsPermission::Export, 'app');     // bool
// Gate::allows('direct-grant', [DocumentsPermission::Export, 'app']);
// $grants = AzGuard::activeGrants($user, 'app');                 // Collection
//
// ── Route middleware (строка неизбежна) ─────────────────────────────────────
//
// Route::get('/export', ExportController::class)
//     ->middleware('az.grant:' . DocumentsPermission::Export->value . ',app');
// // 401 — не аутентифицирован; 403 — гранта нет или истёк.
//
// ── TTL и очистка ───────────────────────────────────────────────────────────
//
// Грант с expires_at < now() неактивен во всех проверках.
// Чистка истёкших записей — в scheduler (bootstrap/app.php):
//
// ->withSchedule(function (Schedule $schedule) {
//     $schedule->command('az-guard:prune-grants')->daily();
// })
//
// ── События ─────────────────────────────────────────────────────────────────
//
// GrantGiven   — после каждого give()
// GrantRevoked — после каждого revoke() / revokeAll()
//
// Event::listen(GrantGiven::class, fn (GrantGiven $e) =>
//     Log::info("Grant [{$e->permissionKey}] issued to user #{$e->user->getAuthIdentifier()}"));
