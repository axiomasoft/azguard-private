> Scope: this example belongs to the project-adopted pattern in `../SKILL.md`.
> It does not impose this architecture on another project; preserve real access and transaction invariants.

# Organizing routes (routes/web.php, routes/api.php)

<!-- Source: anonymized production Laravel project -->

## Principles

- `routes/web.php` — main file; routes are grouped by domain via fluent-chain `Route::controller()->prefix()->name()->group()`.
- Middleware (`auth`, `verified`, custom access checks) hang themselves **at group level**, not to individual routes.
- Domain transitions of nested processes are placed in resource subprefixes: `documents/{document}/review`, names - `documents.review.*` (symmetrically for each subprocess).
- Mutations - only POST/PUT/PATCH/DELETE; forms with real-time is obtained by validation middleware `precognitive`.
- `routes/api.php` minimal: only external integrations, required with rate limit (`throttle:30,1`).

## routes/web.php

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Document\DocumentsController;
use App\Http\Controllers\Document\ReviewController;
use App\Http\Controllers\MainController;
use App\Http\Controllers\Notification\NotificationsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get(uri: '/', action: fn (Request $request) => redirect()->to(path: $request->user() ? '/dashboard' : '/login'));

// Middleware at group level: auth + verified (+ design access checks).
Route::middleware(['auth', 'verified'])->group(callback: function (): void {
    Route::get(uri: '/dashboard', action: [MainController::class, 'dashboard'])->name(name: 'dashboard');

    // Primary domain resource: Route::controller()->prefix()->name()->group().
    Route::controller(DocumentsController::class)
        ->prefix('documents')->name('documents.')
        ->group(function (): void {
            Route::get(uri: '/', action: 'list')->name('list');
            Route::get(uri: '/search', action: 'search')->name(name: 'search');
            Route::get(uri: '/validation-schema', action: 'validationSchema')->name(name: 'validation-schema');
            Route::get(uri: '/create', action: 'create')->name(name: 'create');
            Route::get(uri: '/{document}/edit', action: 'edit')->name(name: 'edit');
            Route::get(uri: '/{document}', action: 'show')->name(name: 'show');
            Route::get(uri: '/{document}/export', action: 'export')->name(name: 'export');

            // Common domain mutations: creation, members, registration, finalization.
            // precognitive — real-time form validation (Laravel Precognition).
            Route::post(uri: '/store', action: 'store')->middleware(middleware: ['precognitive'])->name(name: 'store');
            Route::post(uri: '/{document}/participants', action: 'storeParticipants')->name(name: 'store-participants');
            Route::post(uri: '/{document}/register', action: 'register')->middleware(middleware: ['precognitive'])->name(name: 'register');
            Route::post(uri: '/{document}/finish', action: 'finish')->name(name: 'finish');
        });

    // Resource subprocess - separate controller and domain sub-prefix documents/{document}/review.
    Route::controller(ReviewController::class)
        ->prefix('documents/{document}/review')->name('documents.review.')
        ->group(function (): void {
            Route::post(uri: '/take-in-work', action: 'takeInWork')->name(name: 'take-in-work');
            Route::post(uri: '/reply', action: 'reply')->name(name: 'reply');
            Route::post(uri: '/approve-decision', action: 'approveDecision')->name(name: 'approve-decision');
            Route::post(uri: '/under-revision', action: 'underRevision')->name(name: 'under-revision');
        });

    // Auxiliary domains - the same scheme: controller + prefix + name + group.
    Route::controller(NotificationsController::class)->prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/list', 'list')->name('list');
        Route::patch('/update', 'update')->name('update');
        Route::get('/{id}', 'detail')->name('detail');
    });
});

require __DIR__.'/auth.php';
```

## routes/api.php — minimum, with throttle

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Document\ExternalOrdersController;
use Illuminate\Support\Facades\Route;

// External integrations only; everyone endpoint — with rate limit.
Route::post('/external/orders', [ExternalOrdersController::class, 'store'])
    ->middleware(middleware: ['throttle:30,1'])
    ->name('api.external.orders.store');
```

## Checklist

- [ ] Group = domain: `Route::controller()->prefix()->name()->group()`
- [ ] `auth`, `verified` and access checks - at the parent group level
- [ ] Subprocesses - in resource subprefix (`documents/{document}/review`), names are symmetrical (`documents.review.*`)
- [ ] Mutations only POST/PUT/PATCH/DELETE; forms with live-validation - `precognitive`
- [ ] `api.php` minimal; external integrations - `throttle:30,1`
- [ ] URL kebab-case, route names via `name()` from the group + short name for the route
