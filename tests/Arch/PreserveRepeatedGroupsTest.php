<?php

declare(strict_types=1);

use AzGuard\Tests\Support\PreserveRepeatedGroups;

it('joins repeated groups when pest leaves a hole in argv', function (): void {
    $arguments = [
        'vendor/bin/pest',
        '--compact',
        '--fail-on-skipped',
        '--exclude-group',
        'engines',
        '--exclude-group',
        'redis',
        '--parallel',
        '--processes=4',
        '--ci',
        '--passthru-php=-d memory_limit=1G',
        '--filter',
        'EngineLane',
        'tests/Arch/EngineLaneGroupingTest.php',
        '--exclude-group=replica',
        '--group',
        'database',
        '--group=extended',
        null,
    ];
    unset($arguments[1]);

    $result = (new PreserveRepeatedGroups)->handleArguments($arguments);

    expect($result)->toBe([
        'vendor/bin/pest',
        '--fail-on-skipped',
        '--exclude-group=engines',
        '--exclude-group=redis',
        '--parallel',
        '--processes=4',
        '--ci',
        '--passthru-php=-d memory_limit=1G',
        '--filter',
        'EngineLane',
        'tests/Arch/EngineLaneGroupingTest.php',
        '--exclude-group=replica',
        '--group=database',
        '--group=extended',
    ])->and(array_is_list($result))->toBeTrue()
        ->and($result)->each->toBeString()
        ->and($result)->not->toContain(null);
});
