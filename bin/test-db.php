#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * The database lane: the tests whose result depends on the engine (group `database`, tests/zones.json) on one server
 * driver. Everything else runs once on SQLite (`composer test`).
 *
 *   composer test:db -- pgsql                 sequential
 *   composer test:db -- mysql --parallel      4 ParaTest workers, one database each (azguard_<N>_test)
 *   composer test:db -- mariadb --parallel=8  8 workers (CI)
 *   composer test:db -- pgsql --full          all parity seeds (PARITY_SEEDS unset); the default runs seeds 1 and 2
 *   composer test:db -- pgsql --engines       the concurrency and replication tests (group `engines`), sequential
 *   composer test:db -- pgsql --zone=storage  only the database part of one zone (database:<zone>)
 */

const DRIVERS = ['pgsql', 'mysql', 'mariadb'];

$driver = null;
$workers = 0;
$full = false;
$engines = false;
$zones = [];
$extra = [];

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, DRIVERS, true)) {
        $driver = $argument;
    } elseif ($argument === '--parallel') {
        $workers = 4;
    } elseif (preg_match('/^--parallel=(\d+)$/', $argument, $match) === 1) {
        $workers = max(1, (int) $match[1]);
    } elseif ($argument === '--full') {
        $full = true;
    } elseif ($argument === '--engines') {
        $engines = true;
    } elseif (preg_match('/^--zone=([a-z-]+)$/', $argument, $match) === 1) {
        $zones[] = $match[1];
    } else {
        $extra[] = $argument;
    }
}

if ($driver === null) {
    fwrite(STDERR, 'Usage: composer test:db -- <'.implode('|', DRIVERS)."> [--parallel[=N]] [--full] [--engines] [--zone=<zone>]\n");
    exit(64);
}

$flags = ['--fail-on-skipped'];

if ($engines) {
    $flags[] = '--group=engines';
} else {
    // `server-skip` marks the SQLite-only tests and the ones that need a stand of their own (engines, replica, Redis).
    $flags[] = '--exclude-group=server-skip';

    foreach ($zones === [] ? ['database'] : array_map(fn (string $zone): string => "database:{$zone}", $zones) as $group) {
        $flags[] = '--group='.$group;
    }
}

if ($workers > 0 && ! $engines) {
    array_push($flags, '--parallel', "--processes={$workers}", "--passthru-php='-d memory_limit=1G'");
}

$command = 'php -d memory_limit=1G vendor/bin/pest '.implode(' ', $flags).($extra === [] ? '' : ' '.implode(' ', array_map('escapeshellarg', $extra)));
$environment = ['DB_CONNECTION' => $driver, ...($full ? ['AZGUARD_FULL_SEEDS' => '1'] : []), ...($full || $engines ? [] : ['PARITY_SEEDS' => '1,2'])];

fwrite(STDOUT, '['.$driver.($full ? ', all parity seeds' : '').'] '.$command."\n");

foreach ($environment as $name => $value) {
    putenv("{$name}={$value}");
}

passthru('cd '.escapeshellarg(dirname(__DIR__))." && {$command}", $status);

exit($status);
