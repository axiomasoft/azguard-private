<?php

declare(strict_types=1);

it('keeps the frozen 0.3 tree out of every composer autoload map', function (): void {
    $root = dirname(__DIR__, 2);
    $maps = [
        require $root.'/vendor/composer/autoload_psr4.php',
        require $root.'/vendor/composer/autoload_classmap.php',
        require $root.'/vendor/composer/autoload_namespaces.php',
    ];

    $paths = [];
    foreach ($maps as $map) {
        foreach ($map as $entry) {
            array_push($paths, ...(array) $entry);
        }
    }

    $legacy = array_filter(
        $paths,
        static fn (string $path): bool => str_contains(str_replace('\\', '/', $path), '/legacy/'),
    );

    expect($legacy)->toBeEmpty();
});
