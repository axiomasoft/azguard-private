<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bin/repository-settings-audit.php';

/*
 * Repository settings audit (knowledge/governance#audit-script): the clean fixture passes and each fixture breaks exactly
 * the rule it is named after, so an audit that stops detecting a rule fails here. Fixtures mirror the GitHub REST shapes.
 */

function auditRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> */
function auditFixtureViolations(string $name): array
{
    $directory = __DIR__.'/Fixtures/RepositorySettings/'.$name;
    $expected = [];

    foreach (glob(auditRoot().'/.github/rulesets/*.json') ?: [] as $file) {
        if (basename($file) !== 'required-checks.json') {
            $body = auditReadJson($file);
            $expected[$body['name']] = $body;
        }
    }

    $listing = auditReadJson($directory.'/rulesets.json');
    $details = [];

    foreach ($listing as $row) {
        $details[$row['id']] = auditReadJson($directory.'/ruleset-'.$row['id'].'.json');
    }

    return settingsAuditViolations(
        $expected,
        $listing,
        $details,
        auditWorkflowJobNames(auditRoot().'/.github/workflows'),
        auditReadJson(auditRoot().'/.github/rulesets/required-checks.json')['contexts'],
        auditReadJson($directory.'/repo.json'),
        auditReadJson($directory.'/pvr.json'),
    );
}

it('finds no violation in the clean fixture', function (): void {
    expect(auditFixtureViolations('clean'))->toBe([]);
});

it('detects the rule each negative fixture is named after', function (string $fixture, string $rule): void {
    $violations = auditFixtureViolations($fixture);

    expect($violations)->not->toBe([])
        ->and(array_values(array_unique(array_map(static fn (string $line): string => explode(' ', $line)[0], $violations))))->toBe([$rule]);
})->with([
    'ruleset-missing' => ['ruleset-missing', 'S1'],
    'ruleset-disabled' => ['ruleset-disabled', 'S2'],
    'bypass-actor' => ['bypass-actor', 'S3'],
    'rule-missing' => ['rule-missing', 'S4'],
    'rule-parameter' => ['rule-parameter', 'S5'],
    'check-missing' => ['check-missing', 'S6'],
    'pvr-disabled' => ['pvr-disabled', 'S8'],
    'secret-scanning-disabled' => ['secret-scanning-disabled', 'S9'],
    'push-protection-disabled' => ['push-protection-disabled', 'S10'],
]);

it('flags a required context that no workflow job produces', function (): void {
    $directory = __DIR__.'/Fixtures/RepositorySettings/clean';
    $expected = [];

    foreach (glob(auditRoot().'/.github/rulesets/*.json') ?: [] as $file) {
        if (basename($file) !== 'required-checks.json') {
            $body = auditReadJson($file);
            $expected[$body['name']] = $body;
        }
    }

    $contexts = auditReadJson(auditRoot().'/.github/rulesets/required-checks.json')['contexts'];
    $violations = settingsAuditViolations($expected, [], [], array_slice(auditWorkflowJobNames(auditRoot().'/.github/workflows'), 1), $contexts, auditReadJson($directory.'/repo.json'), auditReadJson($directory.'/pvr.json'));

    expect(array_filter($violations, static fn (string $line): bool => str_starts_with($line, 'S7 ')))->not->toBe([]);
});

it('keeps main.json equal to required-checks.json', function (): void {
    $main = auditReadJson(auditRoot().'/.github/rulesets/main.json');
    $checks = array_values(array_filter($main['rules'], static fn (array $rule): bool => $rule['type'] === 'required_status_checks'))[0]['parameters']['required_status_checks'];

    expect(array_column($checks, 'context'))->toBe(auditReadJson(auditRoot().'/.github/rulesets/required-checks.json')['contexts']);
});

it('names every sensitive path in CODEOWNERS', function (): void {
    $owners = (string) file_get_contents(auditRoot().'/.github/CODEOWNERS');

    foreach (['*', '/.github/', '/compatibility.json', '/bin/api-*', '/bin/release-*', '/RELEASING.md', '/SECURITY.md', '/packages/core/database/', '/packages/core/src/Authorization/', '/packages/core/src/Changes/', '/packages/core/src/Storage/', '/packages/filament/src/'] as $path) {
        expect($owners)->toMatch('#^'.preg_quote($path, '#').'\s+@academici$#m');
    }
});
