<?php
namespace Vendor\Package;

use Illuminate\Support\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
    public function boot(): void { $this->publishes([__DIR__.'/../config/package.php' => config_path('package.php')]); }
}
