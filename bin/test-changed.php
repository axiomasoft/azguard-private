#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Runs only the tests of the zones a change touches and of the zones that depend on them (tests/zones.json).
 *
 *   composer test:changed                   changes against HEAD (staged, unstaged, untracked)
 *   composer test:changed -- --base=main    changes since the merge base with main
 *   composer test:changed -- --quick        skip the tail zones (acceptance, regression)
 *   composer test:changed -- --dry-run      print the selection and the command, run nothing
 *   composer test:changed -- --db           also run the `database` lane of the selection on DB_CONNECTION
 *   composer test:changed -- --files=a,b    take the file list from the argument instead of git
 *
 * A change to the test harness (Pest.php, TestCase, composer files, fixtures) selects nothing: the script says so
 * and exits 2, since only the full suite is a safe answer there.
 */

$root = dirname(__DIR__);
$map = json_decode((string) file_get_contents($root.'/tests/zones.json'), true, flags: JSON_THROW_ON_ERROR);
$zones = $map['zones'];

$options = ['base' => null, 'files' => null, 'quick' => false, 'dry-run' => false, 'db' => false];
$passthrough = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--base=(.+)$/', $argument, $match) === 1) {
        $options['base'] = $match[1];
    } elseif (preg_match('/^--files=(.+)$/', $argument, $match) === 1) {
        $options['files'] = explode(',', $match[1]);
    } elseif (in_array($argument, ['--quick', '--dry-run', '--db'], true)) {
        $options[substr($argument, 2)] = true;
    } else {
        $passthrough[] = $argument;
    }
}

/** @return list<string> */
function changedFiles(string $root, ?string $base): array
{
    $commands = $base === null
        ? ['git diff --name-only HEAD', 'git ls-files --others --exclude-standard']
        : ['git diff --name-only '.escapeshellarg($base).'...HEAD', 'git diff --name-only HEAD', 'git ls-files --others --exclude-standard'];
    $files = [];

    foreach ($commands as $command) {
        $output = shell_exec("cd ".escapeshellarg($root)." && {$command} 2>/dev/null");
        $files = [...$files, ...array_filter(explode("\n", (string) $output))];
    }

    return array_values(array_unique($files));
}

function under(string $file, string $path): bool
{
    return $file === $path || str_starts_with($file, rtrim($path, '/').'/');
}

$files = $options['files'] ?? changedFiles($root, $options['base']);
$files = array_values(array_filter($files, fn (string $file): bool => ! str_starts_with($file, 'plans/')));

if ($files === []) {
    fwrite(STDOUT, "No changes: nothing to run.\n");
    exit(0);
}

$touched = [];
$unmapped = [];
$global = [];

foreach ($files as $file) {
    foreach ($map['global'] as $path) {
        if (under($file, $path)) {
            $global[] = $file;

            continue 2;
        }
    }

    $found = false;

    foreach ($zones as $name => $zone) {
        foreach ([...$zone['src'], ...array_map(fn (string $path): string => 'tests/'.$path, $zone['tests'])] as $path) {
            if (under($file, $path)) {
                $touched[$name] = true;
                $found = true;
            }
        }
    }

    if (! $found && (str_ends_with($file, '.php') || str_ends_with($file, '.json'))) {
        $unmapped[] = $file;
    }
}

if ($global !== []) {
    fwrite(STDERR, "The change touches the test harness or the dependencies:\n  ".implode("\n  ", $global)."\nOnly the full suite is a safe answer: run `composer test`.\n");
    exit(2);
}

// Close over the dependents: a zone runs when it uses a touched zone, directly or through another.
do {
    $before = count($touched);

    foreach ($zones as $name => $zone) {
        if (array_intersect($zone['uses'], array_keys($touched)) !== []) {
            $touched[$name] = true;
        }
    }
} while (count($touched) !== $before);

foreach ($zones as $name => $zone) {
    if (($zone['always'] ?? false) && $touched !== []) {
        $touched[$name] = true;
    }
}

$selected = array_keys(array_filter($zones, fn (array $zone, string $name): bool => isset($touched[$name])
    && ! ($options['quick'] && ($zone['tail'] ?? false)), ARRAY_FILTER_USE_BOTH));
// `engines` need their own services and run through `composer test:engines:<driver>`.
$selected = array_values(array_diff($selected, ['engines']));

if ($selected === []) {
    fwrite(STDOUT, "No zone selected for: ".implode(', ', $files)."\n");
    exit($unmapped === [] ? 0 : 2);
}

// Pest takes one `--group` per group: a comma list selects nothing.
$groupFlags = fn (string $prefix): string => implode(' ', array_map(fn (string $name): string => '--group='.escapeshellarg("{$prefix}:{$name}"), $selected));
fwrite(STDOUT, 'Changed files: '.count($files)."\nZones: ".implode(', ', $selected)."\n");

if ($unmapped !== []) {
    fwrite(STDERR, "Not in any zone (run `composer test` to be safe):\n  ".implode("\n  ", $unmapped)."\n");
}

$base = 'php -d memory_limit=1G vendor/bin/pest --fail-on-skipped --exclude-group=engines --exclude-group=redis --exclude-group=replica '.$groupFlags('zone');
$extra = $passthrough === [] ? '' : ' '.implode(' ', array_map('escapeshellarg', $passthrough));
$commands = [['sqlite', $base.$extra, 'sqlite']];

if ($options['db']) {
    $driver = getenv('DB_CONNECTION') ?: 'sqlite';
    $commands[] = [$driver, 'php -d memory_limit=1G vendor/bin/pest --fail-on-skipped --exclude-group=server-skip '.$groupFlags('database').$extra, $driver];
}

$status = 0;

foreach ($commands as [$label, $command, $connection]) {
    fwrite(STDOUT, "\n[{$label}] {$command}\n");

    if ($options['dry-run']) {
        continue;
    }

    passthru("cd ".escapeshellarg($root).' && DB_CONNECTION='.escapeshellarg($connection)." {$command}", $code);
    $status = $status === 0 ? $code : $status;
}

exit($status);
