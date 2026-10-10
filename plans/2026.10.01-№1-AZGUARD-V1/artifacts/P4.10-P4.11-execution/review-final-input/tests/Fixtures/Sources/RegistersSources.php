<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Facades\AzGuard;
use Closure;
use Illuminate\Support\ServiceProvider;

/**
 * Registers source classes and extensions while the application is still booting.
 */
final class RegistersSources extends ServiceProvider
{
    /** @var list<class-string> */
    public static array $classes = [];

    /** @var array<string, Closure> */
    public static array $extensions = [];

    public static bool $scopedClock = false;

    public function register(): void
    {
        if (self::$scopedClock) {
            $this->app->scoped(ScopedClock::class);
        }

        $sources = AzGuard::sources();

        foreach (self::$classes as $class) {
            $sources->register($class);
        }

        foreach (self::$extensions as $name => $extension) {
            $sources->extend($name, $extension);
        }
    }

    public static function reset(): void
    {
        self::$classes = [];
        self::$extensions = [];
        self::$scopedClock = false;
        LdapSource::$made = [];
    }
}
