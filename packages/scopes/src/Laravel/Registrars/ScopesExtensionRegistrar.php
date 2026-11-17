<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Laravel\Registrars;

use AzGuard\Scopes\Extensions\ScopesExtensionPlugin;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal('Registers AzGuard Scopes Packman extension facets and diagnostics.')]
final class ScopesExtensionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        ExtensionFacetRegistrar::register(
            $app,
            new ScopesExtensionPlugin,
            dirname(__DIR__, 3).'/composer.json',
        );
    }

    public function boot(Application $app): void {}
}
