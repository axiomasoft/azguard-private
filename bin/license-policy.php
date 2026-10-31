<?php

declare(strict_types=1);

/*
 * License policy of the production dependencies (knowledge/security-gates#policies).
 *
 *   php bin/license-policy.php [--input=<licenses.json>] [--policy=<file>] [--today=<YYYY-MM-DD>]
 *
 * Input is the output of `composer licenses --no-dev --format=json` (default: run it). A dependency passes when any of
 * its licenses is in `allowed` (dual licensing) or it has an unexpired entry in `exceptions`. Exit 0: clean; 1: one
 * line `<package> <license>` per violation; 2: usage or input error.
 *
 * The functions are loadable without running the CLI (tests/Arch/SecurityPoliciesTest.php requires this file).
 */

/**
 * @param  array<string, array{license?: list<string>}>  $dependencies
 * @param  array{allowed?: list<string>, exceptions?: array<string, array{expires?: string}>}  $policy
 * @return list<string>
 */
function licenseViolations(array $dependencies, array $policy, string $today): array
{
    $allowed = $policy['allowed'] ?? [];
    $violations = [];

    foreach ($dependencies as $package => $info) {
        $licenses = $info['license'] ?? [];

        if (array_intersect($licenses, $allowed) !== []) {
            continue;
        }

        $expires = $policy['exceptions'][$package]['expires'] ?? null;

        if ($expires !== null && $expires >= $today) {
            continue;
        }

        $violations[] = $package.' '.($licenses === [] ? 'none' : implode(',', $licenses));
    }

    sort($violations);

    return $violations;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(input|policy|today)=(.+)$/', $argument, $match) !== 1) {
            fwrite(STDERR, "license-policy: unknown argument {$argument}\n");
            exit(2);
        }

        $options[$match[1]] = $match[2];
    }

    $policy = json_decode((string) @file_get_contents($options['policy'] ?? $root.'/.github/license-policy.json'), true);
    $raw = isset($options['input'])
        ? (string) @file_get_contents($options['input'])
        : (string) shell_exec('cd '.escapeshellarg($root).' && composer licenses --no-dev --format=json 2>/dev/null');
    $report = json_decode($raw, true);

    if (! is_array($policy) || ! is_array($report) || ! is_array($report['dependencies'] ?? null)) {
        fwrite(STDERR, "license-policy: unreadable policy or licenses report\n");
        exit(2);
    }

    $violations = licenseViolations($report['dependencies'], $policy, $options['today'] ?? gmdate('Y-m-d'));

    foreach ($violations as $violation) {
        echo $violation."\n";
    }

    if ($violations === []) {
        echo 'license-policy: '.count($report['dependencies'])." production dependencies pass\n";
    }

    exit($violations === [] ? 0 : 1);
}
