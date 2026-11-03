<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return list<string> */
function filamentLocalSmokeViolations(array $workflow): array
{
    $job = $workflow['jobs']['consumer-fixture'] ?? [];
    $steps = $job['steps'] ?? [];
    $matches = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? '') === 'Smoke unpublished Filament from local archives'));
    if (count($matches) !== 1 || isset($job['if']) || ($job['continue-on-error'] ?? false) !== false) {
        return ['missing or optional Filament smoke job'];
    }
    $step = $matches[0];
    $run = $step['run'] ?? '';
    if (! is_string($run) || isset($step['if']) || ($step['continue-on-error'] ?? false) !== false
        || ! str_contains($run, 'set -Eeuo pipefail')
        || ! str_contains($run, 'git diff --exit-code -- packages/core packages/filament')
        || ! str_contains($run, 'for policy in lowest latest; do')
        || ! str_contains($run, 'bash bin/consumer-fixture.sh --source=archive --with-filament')
        || ! str_contains($run, '--policy="$policy"')
        || str_contains($run, '--source=packagist')) {
        return ['missing or optional local archive smoke'];
    }
    if (! in_array('consumer-fixture', $workflow['jobs']['all']['needs'] ?? [], true)) {
        return ['aggregate omits smoke job'];
    }

    return [];
}

it('requires unpublished Filament archive smoke for both dependency policies', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml');
    expect(filamentLocalSmokeViolations($workflow))->toBe([]);

    $missing = $workflow;
    $missing['jobs']['consumer-fixture']['steps'] = array_values(array_filter(
        $missing['jobs']['consumer-fixture']['steps'],
        static fn (array $step): bool => ($step['name'] ?? '') !== 'Smoke unpublished Filament from local archives',
    ));
    expect(filamentLocalSmokeViolations($missing))->not->toBe([]);

    foreach ($workflow['jobs']['consumer-fixture']['steps'] as $index => $step) {
        if (($step['name'] ?? '') === 'Smoke unpublished Filament from local archives') {
            $optional = $workflow;
            $optional['jobs']['consumer-fixture']['steps'][$index]['continue-on-error'] = true;
            expect(filamentLocalSmokeViolations($optional))->not->toBe([]);
            $wrongSource = $workflow;
            $wrongSource['jobs']['consumer-fixture']['steps'][$index]['run'] = str_replace('--source=archive', '--source=packagist', $step['run']);
            expect(filamentLocalSmokeViolations($wrongSource))->not->toBe([]);
            $missingPolicy = $workflow;
            $missingPolicy['jobs']['consumer-fixture']['steps'][$index]['run'] = str_replace('lowest latest', 'latest', $step['run']);
            expect(filamentLocalSmokeViolations($missingPolicy))->not->toBe([]);
        }
    }
    $withoutAggregate = $workflow;
    $withoutAggregate['jobs']['all']['needs'] = array_values(array_diff($workflow['jobs']['all']['needs'], ['consumer-fixture']));
    expect(filamentLocalSmokeViolations($withoutAggregate))->not->toBe([]);
});
