<?php

declare(strict_types=1);

namespace AzGuard\Filament\Laravel\Registrars;

use AzGuard\Filament\Extensions\AzGuardFilamentPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal('Registers AzGuard Filament Packman extension facets and configuration schema.')]
final class AzGuardFilamentExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        ExtensionFacetRegistrar::register(
            $app,
            new AzGuardFilamentPlugin,
            dirname(__DIR__, 3).'/composer.json',
        );
    }

    public function boot(Application $app): void {}
}
