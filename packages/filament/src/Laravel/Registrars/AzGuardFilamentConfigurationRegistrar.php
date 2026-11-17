<?php

declare(strict_types=1);

namespace AzGuard\Filament\Laravel\Registrars;

use AzGuard\Filament\Authorization\AzGuardFilamentSurfaceGate;
use AzGuard\Filament\Internal\Exports\RefusesUnverifiableDownload;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal('Registers AzGuard Filament surface gate and export download guard.')]
final class AzGuardFilamentConfigurationRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $app->singleton(FilamentSurfaceGate::class, AzGuardFilamentSurfaceGate::class);
        $app->bindIf(DownloadExport::class, RefusesUnverifiableDownload::class);
    }

    public function boot(Application $app): void {}
}
