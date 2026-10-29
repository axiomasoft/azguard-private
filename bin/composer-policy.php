<?php

declare(strict_types=1);

/*
 * Composer plugin and script policy (knowledge/security-gates#policies).
 *
 *   php bin/composer-policy.php [--composer=<file>...] [--policy=<file>]
 *
 * Default files: the root composer.json and packages/*\/composer.json. Fails when `config.allow-plugins` is `true` or
 * allows a plugin missing from `.github/composer-policy.json`, when a package under `packages/` declares `scripts`, or
 * when a script calls a forbidden network tool. Exit 0: clean; 1: one line per violation; 2: usage or input error.
 *
 * The functions are loadable without running the CLI (tests/Arch/SecurityPoliciesTest.php requires this file).
 */

/**
 * @param  array<string, mixed>  $composer
 * @param  array{plugins?: array<string, string>, forbidden_script_commands?: list<string>}  $policy
 * @return list<string>
 */
function composerPolicyViolations(string $file, array $composer, array $policy, bool $isPackage): array
{
    $violations = [];
    $plugins = $composer['config']['allow-plugins'] ?? [];

    if ($plugins === true) {
        $violations[] = "{$file}: config.allow-plugins is true";
    } elseif (is_array($plugins)) {
        foreach ($plugins as $plugin => $allowed) {
            if ($allowed === true && ! isset($policy['plugins'][$plugin])) {
                $violations[] = "{$file}: plugin {$plugin} is not in the policy";
            }
        }
    }

    $scripts = $composer['scripts'] ?? [];

    if ($isPackage && $scripts !== []) {
        $violations[] = "{$file}: a package composer.json declares scripts";
    }

    foreach ($scripts as $name => $commands) {
        foreach ((array) $commands as $command) {
            foreach ($policy['forbidden_script_commands'] ?? [] as $tool) {
                if (is_string($command) && preg_match('/(?<![\w.\/-])'.preg_quote($tool, '/').'(?![\w-])/', $command) === 1) {
                    $violations[] = "{$file}: script {$name} calls {$tool}";
                }
            }
        }
    }

    return $violations;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $files = [];
    $policyFile = $root.'/.github/composer-policy.json';

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(composer|policy)=(.+)$/', $argument, $match) !== 1) {
            fwrite(STDERR, "composer-policy: unknown argument {$argument}\n");
            exit(2);
        }

        if ($match[1] === 'policy') {
            $policyFile = $match[2];
        } else {
            $files[] = $match[2];
        }
    }

    $explicit = $files !== [];
    $files = $explicit ? $files : [$root.'/composer.json', ...(glob($root.'/packages/*/composer.json') ?: [])];
    $policy = json_decode((string) @file_get_contents($policyFile), true);

    if (! is_array($policy)) {
        fwrite(STDERR, "composer-policy: unreadable policy {$policyFile}\n");
        exit(2);
    }

    $violations = [];

    foreach ($files as $file) {
        $composer = json_decode((string) @file_get_contents($file), true);

        if (! is_array($composer)) {
            fwrite(STDERR, "composer-policy: unreadable {$file}\n");
            exit(2);
        }

        $isPackage = ! $explicit && $file !== $root.'/composer.json';
        array_push($violations, ...composerPolicyViolations(str_replace($root.'/', '', $file), $composer, $policy, $isPackage));
    }

    foreach ($violations as $violation) {
        echo $violation."\n";
    }

    if ($violations === []) {
        echo 'composer-policy: '.count($files)." composer files pass\n";
    }

    exit($violations === [] ? 0 : 1);
}
