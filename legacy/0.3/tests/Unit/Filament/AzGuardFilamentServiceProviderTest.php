<?php

declare(strict_types=1);

use AzGuard\Filament\AzGuardFilamentServiceProvider;
use Illuminate\Support\ServiceProvider;

it('boots without exception when views directory does not exist', function () {
    $app = app();

    $provider = new AzGuardFilamentServiceProvider($app);

    expect(fn () => $provider->boot())->not->toThrow(Throwable::class);
});

it('is a ServiceProvider', function () {
    expect(AzGuardFilamentServiceProvider::class)
        ->toExtend(ServiceProvider::class);
});
