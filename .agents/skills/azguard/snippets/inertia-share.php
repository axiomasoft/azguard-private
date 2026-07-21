<?php

// Source: azguard package docs (package: azguard/azguard)

declare(strict_types=1);

namespace App\Http\Middleware;

use App\AzGuard\App\Permissions\DocumentsPermission;
use App\AzGuard\App\Permissions\ReportsPermission;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Глобальная карта прав текущего пользователя для Inertia SPA —
 * доступна как prop на каждой странице. Шарь только то, что нужно
 * фронтенду; пер-ресурсные флаги — через Abilities DTO (abilities-dto.php).
 *
 * ВАЖНО: фронтенд-проверки — только UX (скрыть/показать кнопку),
 * не безопасность. Каждое действие валидируется на сервере
 * (Gate::allows() / политика / middleware).
 */
class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user() ? [
                    'documents' => [
                        'view' => $request->user()->hasPermission(DocumentsPermission::View),
                        'create' => $request->user()->hasPermission(DocumentsPermission::Create),
                        'edit' => $request->user()->hasPermission(DocumentsPermission::Edit),
                        'delete' => $request->user()->hasPermission(DocumentsPermission::Delete),
                    ],
                    'reports' => [
                        'view' => $request->user()->hasPermission(ReportsPermission::View),
                        'export' => $request->user()->hasPermission(ReportsPermission::Export),
                    ],
                ] : [],
            ],
        ]);
    }
}

// ── TypeScript-константы прав ───────────────────────────────────────────────
//
// php artisan azguard:export-ts
// # outputs: resources/js/permissions.ts (auto-generated, do not edit)
//
// export const Permissions = {
//   app: {
//     documents: {
//       view:   'app.documents.view',
//       create: 'app.documents.create',
//     },
//   },
// } as const;
//
// ── Потребление в Vue (computed по page.props) ──────────────────────────────
//
// <!-- resources/js/Pages/Documents/Index.vue -->
// <script setup lang="ts">
// import { computed } from 'vue'
// import { usePage } from '@inertiajs/vue3'
// import { Permissions } from '@/permissions'
//
// const page = usePage()
//
// const canCreate = computed(
//   () => page.props.auth.permissions?.documents?.create
// )
// </script>
//
// <template>
//   <CreateButton v-if="canCreate" />
// </template>
