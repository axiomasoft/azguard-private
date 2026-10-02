<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

const AZGUARD_TASK_CODE = '/\bPLAN\d+\b|\bP\d+\.\d+\b|\b[CDNQRV]-?\d{2,3}\b|\bF\d{2}\b/';

/**
 * @return list<string> task codes found in comments of the given PHP code; code and strings are not scanned
 */
function taskCodesInComments(string $code): array
{
    $found = [];

    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            && preg_match_all(AZGUARD_TASK_CODE, $token[1], $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

it('finds task codes only in comments', function (): void {
    $code = <<<'PHP'
        <?php
        // see P1.4 and PLAN2
        /** Closes N13, C-11, V117 and F01. */
        $ignored = 'P1.4 in a string';
        /* sha256, UTF-8, 0x21, ID64, P1 and 1.4 are not codes */
        PHP;

    expect(taskCodesInComments($code))->toBe(['P1.4', 'PLAN2', 'N13', 'C-11', 'V117', 'F01']);
});

it('keeps internal task codes out of source comments', function (): void {
    $offenders = [];

    foreach (SourceScan::files() as $file) {
        foreach (taskCodesInComments((string) file_get_contents($file)) as $code) {
            $offenders[] = basename($file).': '.$code;
        }
    }

    expect($offenders)->toBe([]);
});

it('reads package configuration only in the configuration zone', function (): void {
    $offenders = array_values(array_filter(
        SourceScan::files(),
        static fn (string $file): bool => ! str_contains($file, '/packages/core/src/Configuration/')
            && preg_match('/config\(\s*[\'"]azguard/', (string) file_get_contents($file)) === 1,
    ));

    expect($offenders)->toBe([]);
});

it('tags every contract as api or spi', function (): void {
    $untagged = array_values(array_filter(
        SourceScan::files(),
        static fn (string $file): bool => str_contains($file, '/packages/core/src/Contracts/')
            && preg_match('/^\s*\*\s*@(api|spi)\b/m', (string) file_get_contents($file)) !== 1,
    ));

    expect($untagged)->toBe([]);
});
