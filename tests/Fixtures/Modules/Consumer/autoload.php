<?php

declare(strict_types=1);

/*
 * The independent consumer (PLAN1 P2.14, I8): a package of its own vendor namespace `Acme\Consumer\`, outside
 * `AzGuard\`, that touches AzGuard only through the supported slots. The test environment has no Composer mapping for
 * it, so the fixture registers its own PSR-4 loader.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Acme\\Consumer\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});
