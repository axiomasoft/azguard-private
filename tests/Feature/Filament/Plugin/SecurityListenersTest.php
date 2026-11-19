<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Filament\Internal\Authorization\AuthorizesActions;
use AzGuard\Filament\Internal\Exports\AuthorizesExports;
use Filament\Actions\Action;
use Filament\Actions\Events\ActionCalling;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Packman\Filament\Security\Authorization\AuthorizesActions as PackmanAuthorizesActions;
use Packman\Filament\Security\Authorization\AuthorizesExports as PackmanAuthorizesExports;

it('preserves existing ActionCalling guards and attaches its own listeners once per dispatcher', function (): void {
    $original = app(Dispatcher::class);
    $events = new EventDispatcher(app());
    $calls = [];
    $events->listen(ActionCalling::class, static function (Action $action) use (&$calls): void {
        $calls[] = $action->getName();
    });
    $events->listen(ActionCalling::class, PackmanAuthorizesActions::class);
    $events->listen(ActionCalling::class, PackmanAuthorizesExports::class);
    app()->instance('events', $events);

    try {
        $provider = app()->getProvider(AzGuardFilamentServiceProvider::class);
        $provider->boot();
        $provider->boot();
        $events->dispatch(ActionCalling::class, Action::make('sentinel'));

        expect($calls)->toBe(['sentinel']);
        $listeners = $events->getRawListeners()[ActionCalling::class];
        expect($listeners)->toHaveCount(5);

        foreach ([PackmanAuthorizesActions::class, PackmanAuthorizesExports::class, AuthorizesActions::class, AuthorizesExports::class] as $listener) {
            expect(array_keys($listeners, $listener, true))->toHaveCount(1);
        }
    } finally {
        app()->instance('events', $original);
    }
});
