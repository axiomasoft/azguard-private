<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

/**
 * Centralized coverage rules PHP-code (coverage.php in the project root).
 *
 * Usage:
 * - baseline and reads thresholds `scripts/check-coverage-gate.php` (after run
 *   with PCOV/Xdebug and generation `coverage/clover.xml`);
 * - environment variable `COVERAGE_GATE_MODE`: report | soft | hard (see script).
 *
 * @return array{
 *     baseline_total_percent: float,
 *     global_minimum_percent: float,
 *     critical_directories_minimum_percent: float,
 *     source_roots: list<string>,
 *     exclude_path_substrings: list<string>,
 *     critical_path_prefixes: list<string>,
 * }
 */
return [
    /** Baseline; confirm with a full run with PCOV in CI */
    'baseline_total_percent' => 70.0,

    /**
     * Minimum percentage of covered lines for the project (line-rate in Clover).
     * Pinned to by default baseline, to gate worked without additional. env.
     */
    'global_minimum_percent' => (float) (getenv('COVERAGE_GLOBAL_MIN') !== false
        ? getenv('COVERAGE_GLOBAL_MIN')
        : 70.0),

    /** Minimum «critical» prefixes (is used in coverage gate). */
    'critical_directories_minimum_percent' => (float) (getenv('COVERAGE_CRITICAL_MIN') !== false
        ? getenv('COVERAGE_CRITICAL_MIN')
        : 55.0),

    'source_roots' => [
        'app',
    ],

    'exclude_path_substrings' => [
        'vendor/',
        'tests/',
        'bootstrap/cache/',
    ],

    /** File path prefixes in Clover to check critical areas. */
    'critical_path_prefixes' => [
        'app/Http/Middleware',
        'app/Policies',
        'app/Services',
        'app/Actions',
    ],
];
