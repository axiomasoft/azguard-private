<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\AzGuard\App\Documents\Policies;

use App\AzGuard\App\Permissions\DocumentsPermission;
use App\Models\Document;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

/**
 * Авторегистрация: при boot панели PanelProvider рекурсивно сканирует
 * каталоги Policies/ (файлы *Policy.php), читает атрибуты и сам вызывает:
 *   - Gate::define(resolvedAbility, [Policy::class, 'method'])
 *     для каждого метода с #[GateAbility(permission: ...)];
 *   - Gate::policy(Document::class, DocumentsPolicy::class)
 *     по #[GuardPolicy(model: ...)].
 * Ничего руками в AuthServiceProvider регистрировать не нужно.
 *
 * Gate::before зарегистрирован самим AzGuard и срабатывает ТОЛЬКО для
 * wildcard-ролей (permissions() === ['*']) — политика тогда не вызывается.
 * Вся остальная логика — здесь, через hasAzPermission().
 */
#[GuardPolicy(model: Document::class)]
class DocumentsPolicy
{
    #[GateAbility(permission: DocumentsPermission::View)]
    public function canView(User $user, Document $document): bool
    {
        return $user->hasAzPermission('app.documents.view')
            && $user->id === $document->owner_id;
    }

    #[GateAbility(permission: DocumentsPermission::Edit)]
    public function canEdit(User $user, Document $document): bool
    {
        return $user->hasAzPermission('app.documents.edit')
            && ! $document->isLocked();
    }

    #[GateAbility(permission: DocumentsPermission::Export)]
    public function canExport(User $user, Document $document): bool
    {
        return $user->hasAzPermission('app.documents.export');
    }
}

// ── Вызов Gate: всегда enum-кейс, не строка ────────────────────────────────
//
// Gate::allows(DocumentsPermission::View, $document);     // bool
// Gate::authorize(DocumentsPermission::Edit, $document);  // 403 при отказе
// $this->authorize(DocumentsPermission::Edit, $document); // в контроллере
//
// Blade — передавай готовые bool из контроллера (предпочтительно)
// или FQCN + ->value:
//
// @can(\App\AzGuard\App\Permissions\DocumentsPermission::Edit->value, $document)
//     <button>Edit</button>
// @endcan
//
// @azcan('documents.view')   {{-- короткий ключ, панель из контекста --}}
//     <a href="...">View</a>
// @endazcan
//
// ── Artisan ────────────────────────────────────────────────────────────────
//
// php artisan azguard:make-policy Documents --panel=app
// php artisan azguard:doctor   // найдёт enum-кейсы без policy-метода и orphan-политики
