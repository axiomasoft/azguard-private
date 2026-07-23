# Организация маршрутов (routes/web.php, routes/api.php)

<!-- Source: anonymized production Laravel project -->

## Принципы

- `routes/web.php` — основной файл; маршруты группируются по доменам через fluent-цепочку `Route::controller()->prefix()->name()->group()`.
- Middleware (`auth`, `verified`, кастомные проверки доступа) вешаются **на уровне группы**, не на отдельные роуты.
- Доменные переходы вложенных процессов выносятся в подпрефиксы ресурса: `documents/{document}/review`, имена — `documents.review.*` (симметрично для каждого подпроцесса).
- Мутации — только POST/PUT/PATCH/DELETE; формы с real-time валидацией получают middleware `precognitive`.
- `routes/api.php` минимален: только внешние интеграции, обязательно с rate limit (`throttle:30,1`).

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

// Middleware на уровне группы: auth + verified (+ проектные проверки доступа).
Route::middleware(['auth', 'verified'])->group(callback: function (): void {
    Route::get(uri: '/dashboard', action: [MainController::class, 'dashboard'])->name(name: 'dashboard');

    // Основной ресурс домена: Route::controller()->prefix()->name()->group().
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

            // Общие мутации домена: создание, участники, регистрация, финализация.
            // precognitive — real-time валидация формы (Laravel Precognition).
            Route::post(uri: '/store', action: 'store')->middleware(middleware: ['precognitive'])->name(name: 'store');
            Route::post(uri: '/{document}/participants', action: 'storeParticipants')->name(name: 'store-participants');
            Route::post(uri: '/{document}/register', action: 'register')->middleware(middleware: ['precognitive'])->name(name: 'register');
            Route::post(uri: '/{document}/finish', action: 'finish')->name(name: 'finish');
        });

    // Подпроцесс ресурса — отдельный контроллер и доменный подпрефикс documents/{document}/review.
    Route::controller(ReviewController::class)
        ->prefix('documents/{document}/review')->name('documents.review.')
        ->group(function (): void {
            Route::post(uri: '/take-in-work', action: 'takeInWork')->name(name: 'take-in-work');
            Route::post(uri: '/reply', action: 'reply')->name(name: 'reply');
            Route::post(uri: '/approve-decision', action: 'approveDecision')->name(name: 'approve-decision');
            Route::post(uri: '/under-revision', action: 'underRevision')->name(name: 'under-revision');
        });

    // Вспомогательные домены — та же схема: controller + prefix + name + group.
    Route::controller(NotificationsController::class)->prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/list', 'list')->name('list');
        Route::patch('/update', 'update')->name('update');
        Route::get('/{id}', 'detail')->name('detail');
    });
});

require __DIR__.'/auth.php';
```

## routes/api.php — минимальный, с throttle

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Document\ExternalOrdersController;
use Illuminate\Support\Facades\Route;

// Только внешние интеграции; каждый endpoint — с rate limit.
Route::post('/external/orders', [ExternalOrdersController::class, 'store'])
    ->middleware(middleware: ['throttle:30,1'])
    ->name('api.external.orders.store');
```

## Чеклист

- [ ] Группа = домен: `Route::controller()->prefix()->name()->group()`
- [ ] `auth`, `verified` и проверки доступа — на уровне родительской группы
- [ ] Подпроцессы — в подпрефиксе ресурса (`documents/{document}/review`), имена симметричны (`documents.review.*`)
- [ ] Мутации только POST/PUT/PATCH/DELETE; формы с live-валидацией — `precognitive`
- [ ] `api.php` минимален; внешние интеграции — `throttle:30,1`
- [ ] URL kebab-case, имена роутов через `name()` у группы + короткое имя у роута
