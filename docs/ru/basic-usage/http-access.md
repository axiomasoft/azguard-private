# HTTP-доступ

AzGuard предоставляет декларативный способ защиты действий контроллера на
основе атрибутов. Middleware отвечает за контекст панели; `#[CheckPermission]` —
за проверку конкретного действия.

## Стек middleware

```php
Route::middleware([
    'azguard.panel:app',  // устанавливает текущую панель → права резолвятся как app.*
    'azguard.roles',      // заранее грузит роли и прямые гранты пользователя
    'azguard.check',      // читает #[CheckPermission] на методе контроллера
])->group(function () {
    Route::apiResource('documents', DocumentController::class);
    Route::apiResource('reports', ReportController::class);
});
```

| Middleware | Алиас | Назначение |
|---|---|---|
| `SetCurrentPanel` | `azguard.panel` | Устанавливает активную панель для этого запроса |
| `LoadAzGuardRoles` | `azguard.roles` | Загружает и кеширует роли + гранты для `auth()->user()` |
| `CheckAccess` | `azguard.check` | Читает `#[CheckPermission]` и вызывает `Gate::allows()` |

Все три алиаса регистрируются автоматически через `AzGuardServiceProvider`.

## Статические конструкторы

У каждого middleware AzGuard есть также enum-осведомлённый статический
конструктор `::using()` — альтернатива строковому DSL-алиасу выше (поддерживаются
оба варианта, выбирайте тот, что лучше читается в месте вызова). Порядок
аргументов всегда **что, потом где** (сначала право, потом панель):

```php
use AzGuard\Http\Middleware\CheckAccess;
use AzGuard\Http\Middleware\CheckDirectGrant;
use AzGuard\Http\Middleware\PanelCheckAccess;
use AzGuard\Http\Middleware\SetCurrentPanel;

Route::middleware([
    SetCurrentPanel::using('app'),
    'azguard.roles',
    CheckAccess::using(),
])->group(function () {
    Route::apiResource('documents', DocumentController::class);
});

// Прямой грант — сначала право, потом панель (панель опциональна, по умолчанию текущая):
Route::get('/export', ExportController::class)
    ->middleware(CheckDirectGrant::using(DocumentsPermission::Export, 'app'));

// Комбинированная проверка панели + права — сначала право, потом панель:
Route::get('/reports', ReportsController::class)
    ->middleware(PanelCheckAccess::using(ReportsPermission::View, 'app'));
```

`PanelCheckAccess` принимает `string|BackedEnum` и для права, и для панели;
строковый алиас — `azguard.panel_check:{permission},{panel}` (право первым —
это ломающее pre-1.0 переименование относительно прежнего порядка
`{panel},{permission}`).

## `#[CheckPermission]`

```php
use AzGuard\Attributes\CheckPermission;
use AzGuard\Attributes\SkipGuardCheck;

class DocumentController extends Controller
{
    // Проверка права на просмотр — без биндинга модели
    #[CheckPermission(DocumentsPermission::View)]
    public function index(): Response
    {
        return Inertia::render('Documents/Index', [
            'documents' => Document::paginate(),
        ]);
    }

    // С аргументом-моделью — передаётся в Gate::allows() для интеграции с Policy
    #[CheckPermission(permission: DocumentsPermission::View, arguments: ['document'])]
    public function show(Document $document): Response
    {
        return Inertia::render('Documents/Show', [
            'document'  => $document,
            'abilities' => DocumentsAbilities::fromDocument($document)->toArray(),
        ]);
    }

    #[CheckPermission(permission: DocumentsPermission::Edit, arguments: ['document'])]
    public function update(UpdateDocumentRequest $request, Document $document): Response
    {
        $document->update($request->validated());
        return back()->with('success', 'Document updated.');
    }

    #[CheckPermission(DocumentsPermission::Delete)]
    public function destroy(Document $document): Response
    {
        $document->delete();
        return redirect()->route('documents.index');
    }

    // Полностью пропустить проверку guard для публичных эндпоинтов
    #[SkipGuardCheck]
    public function publicPreview(Document $document): Response
    {
        return Inertia::render('Documents/Preview', ['document' => $document]);
    }
}
```

Массив `arguments` сопоставляется с биндингами модели маршрута по имени
параметра. Middleware резолвит их из запроса и передаёт в
`Gate::allows($ability, [$model])`.

## Ручные проверки Gate

Для кода вне контроллеров (jobs, listeners, services) всегда передавайте
**кейс enum** — никогда не сырую строку:

```php
// ✅ Enum — типобезопасно, навигация по IDE
Gate::authorize(DocumentsPermission::Edit, $document);

if (! Gate::allows(DocumentsPermission::Edit, $document)) {
    throw new AuthorizationException('Cannot edit this document.');
}

// В контроллерах
$this->authorize(DocumentsPermission::Delete, $document);
```

::: tip Route middleware — единственное исключение
Route middleware `'can:'` требует строку. Используйте `->value`, чтобы получить
её из enum:
```php
Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])
    ->middleware('can:' . DocumentsPermission::Edit->value . ',document');
```
:::

## API-роуты (JSON 403)

Для API-роутов настройте ответ об ошибке в вашем exception handler:

```php
// app/Exceptions/Handler.php (стиль Laravel 10)
public function render($request, Throwable $e)
{
    if ($e instanceof AuthorizationException && $request->expectsJson()) {
        return response()->json([
            'message' => 'Forbidden.',
            'error'   => 'insufficient_permissions',
        ], 403);
    }

    return parent::render($request, $e);
}

// Laravel 11 — bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (AuthorizationException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    });
})
```

## Цепочки middleware

Несколько панелей в одном приложении — регистрируйте каждую группу с
собственным middleware панели:

```php
// Панель app
Route::prefix('app')
    ->middleware(['auth', 'azguard.panel:app', 'azguard.roles', 'azguard.check'])
    ->group(base_path('routes/app.php'));

// Панель admin
Route::prefix('admin')
    ->middleware(['auth', 'azguard.panel:admin', 'azguard.roles', 'azguard.check'])
    ->group(base_path('routes/admin.php'));

// API (stateless)
Route::prefix('api')
    ->middleware(['auth:sanctum', 'azguard.panel:api', 'azguard.roles', 'azguard.check'])
    ->group(base_path('routes/api.php'));
```

## Именованные группы middleware

Избавьтесь от дублирования в маршрутах, зарегистрировав именованные группы:

```php
// bootstrap/app.php (Laravel 11)
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('app-guard', [
        \AzGuard\Http\Middleware\SetCurrentPanel::class.':app',
        \AzGuard\Http\Middleware\LoadAzGuardRoles::class,
        \AzGuard\Http\Middleware\CheckAccess::class,
    ]);

    $middleware->appendToGroup('admin-guard', [
        \AzGuard\Http\Middleware\SetCurrentPanel::class.':admin',
        \AzGuard\Http\Middleware\LoadAzGuardRoles::class,
        \AzGuard\Http\Middleware\CheckAccess::class,
    ]);
})
```

```php
// routes/web.php — чисто и читаемо
Route::middleware(['auth', 'app-guard'])
    ->group(base_path('routes/app.php'));

Route::middleware(['auth', 'admin-guard'])
    ->group(base_path('routes/admin.php'));
```

## Защита целых групп роутов по роли

```php
// Отдельный класс middleware
// app/Http/Middleware/RequireRole.php
public function handle(Request $request, Closure $next, string $role): Response
{
    if (! $request->user()?->hasRole($role)) {
        abort(403);
    }
    return $next($request);
}
```

```php
Route::middleware(['auth', 'app-guard', 'role:manager'])
    ->group(function () {
        Route::get('/reports', ReportsController::class);
    });
```
