<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use Filament\FilamentServiceProvider;

it('registers the core provider in the application', function (): void {
    expect(app()->getProvider(AzGuardServiceProvider::class))
        ->toBeInstanceOf(AzGuardServiceProvider::class);
});

it('registers the filament provider when Filament is installed', function (): void {
    if (! class_exists(FilamentServiceProvider::class)) {
        $this->markTestSkipped('Filament is not installed.');
    }

    expect(app()->getProvider(AzGuardFilamentServiceProvider::class))
        ->toBeInstanceOf(AzGuardFilamentServiceProvider::class);
});
