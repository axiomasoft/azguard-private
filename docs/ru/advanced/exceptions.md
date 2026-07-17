# Исключения

## AzGuardException

Базовый класс доменных исключений AzGuard (наследует `RuntimeException`).

Все исключения пакета — `PanelNotFoundException`, `PanelNotSetException`,
`InvalidMorphTypeException` и `InvalidPermissionKeyException` — наследуют
этот класс, поэтому один `catch (AzGuardException)` перехватывает любую
доменную ошибку AzGuard, независимо от под-пространства имён:

```php
use AzGuard\Exceptions\AzGuardException;

try {
    AzGuard::permission('app', 'app.unknown.key');
} catch (AzGuardException $e) {
    Log::error('AzGuard error: ' . $e->getMessage());
}
```

## PanelNotFoundException

Бросается, когда запрошенная панель не зарегистрирована:

```php
use AzGuard\Exceptions\PanelNotFoundException;

try {
    AzGuard::permission('ghost-panel', SomePermission::View);
} catch (PanelNotFoundException $e) {
    Log::warning("Неизвестная панель: {$e->panelId}");
}
```

## PanelNotSetException

Бросается, когда панель не указана явно и нет текущей панели (не отработал
`SetCurrentPanel` middleware и не вызван `->on('panel-id')`).

```php
use AzGuard\Exceptions\PanelNotSetException;
```

## InvalidPermissionKeyException

Бросается, когда ключ права отсутствует в каталоге панели:

```php
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

try {
    AzGuard::permission('app', 'app.posts.flyToMoon');
} catch (InvalidPermissionKeyException $e) {
    // Ключ не зарегистрирован в каталоге панели
    Log::warning($e->getMessage());
}
```

## Отказ в доступе через middleware / `#[CheckPermission]`

Атрибут `#[CheckPermission]` и middleware вызывают `abort_if()` при отсутствии
права — это стандартный `Symfony\Component\HttpKernel\Exception\HttpException`
с кодом 403, который Laravel рендерит как обычный HTTP-ответ:

```php
use Symfony\Component\HttpKernel\Exception\HttpException;

// app/Exceptions/Handler.php
public function render($request, Throwable $e): Response
{
    if ($e instanceof HttpException && $e->getStatusCode() === 403) {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Доступ запрещён.'], 403);
        }

        return redirect()->route('home')
            ->with('error', 'У вас нет доступа к этому разделу.');
    }

    return parent::render($request, $e);
}
```

## Типичные ошибки

| Ошибка | Причина | Решение |
|---|---|---|
| `HttpException` (403) | Пользователь не имеет права | Назначьте роль или грант |
| `InvalidPermissionKeyException` | Право не в каталоге панели | Добавьте enum в `permissionEnums()` панели |
| `PanelNotSetException` | Не задана текущая панель | `SetCurrentPanel` middleware или `->on('app')` |
| `PanelNotFoundException` | Панель не зарегистрирована | Добавьте провайдер в `az-guard.panels` |
