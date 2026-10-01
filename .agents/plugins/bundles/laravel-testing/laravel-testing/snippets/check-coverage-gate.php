<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

/**
 * Coverage gate by Clover after `php artisan test --coverage --coverage-clover=coverage/clover.xml`.
 * Placed in scripts/check-coverage-gate.php; thresholds reads from coverage.php (see coverage-gate-config.php).
 *
 * Environment variables:
 * - COVERAGE_GATE_MODE: report | soft | hard (default hard)
 * - COVERAGE_GLOBAL_MIN: override minimum percentage (lines)
 * - COVERAGE_CRITICAL_MIN: override the minimum for critical directories
 */
$root = dirname(__DIR__);
$cloverPath = $root.'/coverage/clover.xml';
$mode = getenv('COVERAGE_GATE_MODE') ?: 'hard';

$configPath = $root.'/coverage.php';
if (! is_file($configPath)) {
    fwrite(STDERR, "coverage.php not found.\n");

    exit(1);
}

$config = require $configPath;

$minPercent = getenv('COVERAGE_GLOBAL_MIN') !== false
    ? (float) getenv('COVERAGE_GLOBAL_MIN')
    : (float) ($config['global_minimum_percent'] ?? 0.0);

if (! is_file($cloverPath)) {
    $message = "Clover not found: {$cloverPath}. Run tests with PCOV/Xdebug and --coverage.\n";
    if ($mode === 'hard') {
        fwrite(STDERR, $message);

        exit(1);
    }
    echo $message;

    exit(0);
}

$xml = @simplexml_load_file($cloverPath);
if ($xml === false || $xml->project->metrics === null) {
    fwrite(STDERR, "Failed to read metrics from {$cloverPath}\n");

    exit(1);
}

$attrs = $xml->project->metrics->attributes();
$statements = (int) ($attrs['statements'] ?? 0);
$covered = (int) ($attrs['coveredstatements'] ?? 0);
$lineRate = $statements > 0 ? ($covered / $statements) * 100.0 : 0.0;

echo sprintf(
    "Coverage (lines): %.2f%% (%d/%d statements). Baseline: %.2f%%. Min: %.2f%%. Mode: %s\n",
    $lineRate,
    $covered,
    $statements,
    (float) ($config['baseline_total_percent'] ?? 0.0),
    $minPercent,
    $mode,
);

if ($minPercent <= 0.0 || $mode === 'report') {
    exit(0);
}

// General threshold failed.
if ($lineRate + 0.005 < $minPercent) {
    $msg = sprintf("Coating %.2f%% below threshold %.2f%%.\n", $lineRate, $minPercent);
    if ($mode === 'soft') {
        echo $msg;

        exit(0);
    }
    fwrite(STDERR, $msg);

    exit(1);
}

// Verification «critical» zones per file (app/Actions, app/Policies, app/Services, app/Http/Middleware).
$criticalMinPercent = getenv('COVERAGE_CRITICAL_MIN') !== false
    ? (float) getenv('COVERAGE_CRITICAL_MIN')
    : (float) ($config['critical_directories_minimum_percent'] ?? 0.0);

/** @var array<int, string> $criticalPrefixes */
$criticalPrefixes = $config['critical_path_prefixes'] ?? [];
$criticalFailures = [];

if ($criticalMinPercent > 0.0 && $criticalPrefixes !== []) {
    foreach ($xml->xpath('//file') ?: [] as $fileNode) {
        $fileName = str_replace('\\', '/', (string) ($fileNode['name'] ?? ''));
        if ($fileName === '') {
            continue;
        }

        $matched = false;
        foreach ($criticalPrefixes as $prefix) {
            if (str_starts_with($fileName, $prefix)) {
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            continue;
        }

        $fileMetrics = $fileNode->metrics?->attributes();
        $fileStatements = (int) ($fileMetrics['statements'] ?? 0);
        if ($fileStatements === 0) {
            continue;
        }

        $fileCoverage = ((int) ($fileMetrics['coveredstatements'] ?? 0) / $fileStatements) * 100.0;
        if ($fileCoverage + 0.005 < $criticalMinPercent) {
            $criticalFailures[] = sprintf('%s: %.2f%% (< %.2f%%)', $fileName, $fileCoverage, $criticalMinPercent);
        }
    }
}

if ($criticalFailures === []) {
    exit(0);
}

$criticalMessage = "Critical files below threshold:\n - ".implode("\n - ", $criticalFailures)."\n";
if ($mode === 'soft') {
    echo $criticalMessage;

    exit(0);
}

fwrite(STDERR, $criticalMessage);

exit(1);
