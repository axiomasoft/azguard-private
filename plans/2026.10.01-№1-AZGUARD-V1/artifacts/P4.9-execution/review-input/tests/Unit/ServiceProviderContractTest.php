<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use Illuminate\Support\ServiceProvider;

it('declares both providers as final Laravel service providers', function (string $provider): void {
    $reflection = new ReflectionClass($provider);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(ServiceProvider::class))->toBeTrue();
})->with([
    'core' => AzGuardServiceProvider::class,
    'filament' => AzGuardFilamentServiceProvider::class,
]);
