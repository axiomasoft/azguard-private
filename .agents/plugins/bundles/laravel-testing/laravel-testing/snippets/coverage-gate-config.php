<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

/**
 * Централизованные правила покрытия PHP-кода (coverage.php в корне проекта).
 *
 * Использование:
 * - baseline и пороги читает `scripts/check-coverage-gate.php` (после прогона
 *   с PCOV/Xdebug и генерацией `coverage/clover.xml`);
 * - переменная окружения `COVERAGE_GATE_MODE`: report | soft | hard (см. скрипт).
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
    /** Базовый ориентир; подтверждать полным прогоном с PCOV в CI */
    'baseline_total_percent' => 70.0,

    /**
     * Минимальная доля покрытых строк по проекту (line-rate в Clover).
     * По умолчанию закреплена на baseline, чтобы gate работал без доп. env.
     */
    'global_minimum_percent' => (float) (getenv('COVERAGE_GLOBAL_MIN') !== false
        ? getenv('COVERAGE_GLOBAL_MIN')
        : 70.0),

    /** Минимум по «критичным» префиксам (используется в coverage gate). */
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

    /** Префиксы путей файлов в Clover для проверки критичных зон. */
    'critical_path_prefixes' => [
        'app/Http/Middleware',
        'app/Policies',
        'app/Services',
        'app/Actions',
    ],
];
