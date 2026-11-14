<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/WorkflowHardening.php';

/*
 * Workflow hardening gate (knowledge/supply-chain#gate): every `.github/workflows/*.yml` passes rules W1-W10, and each
 * fixture under tests/Arch/Fixtures/Workflows/ breaks exactly the rule it is named after, so a gate that stops
 * detecting a rule fails here.
 */

/** @return list<string> the rule id of each violation string */
function workflowRuleIds(array $violations): array
{
    return array_values(array_unique(array_map(static fn (string $violation): string => explode(' ', $violation)[0], $violations)));
}

/** @return list<string> */
function workflowFileViolations(string $path): array
{
    $raw = (string) file_get_contents($path);

    return workflowHardeningViolations($path, $raw, Yaml::parse($raw));
}

it('finds no violation in root or package workflows', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [...(glob($root.'/.github/workflows/*.yml') ?: []), ...(glob($root.'/packages/*/.github/workflows/*.yml') ?: []), ...(glob($root.'/packages/*/.github/workflows/*.yaml') ?: [])];
    $violations = [];

    foreach ($files as $file) {
        $violations = [...$violations, ...workflowFileViolations($file)];
    }

    expect($files)->toContain($root.'/packages/core/.github/workflows/provenance.yml')
        ->and($violations)->toBe([]);
});

it('rejects an unsafe package workflow and a package omitted from the scanner', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['core'] as $package) {
        $path = $root."/packages/{$package}/.github/workflows/provenance.yml";
        $raw = (string) file_get_contents($path);
        // The production workflow is a fail-closed stub with no checkout.
        // Inject an unsafe checkout into the parsed copy so the negative test
        // remains meaningful without adding an executable step to the stub.
        $unsafe = Yaml::parse($raw);
        $jobId = array_key_first($unsafe['jobs']);
        $unsafe['jobs'][$jobId]['steps'][] = [
            'uses' => 'actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803',
            'with' => ['persist-credentials' => true],
        ];
        expect($unsafe['jobs'][$jobId]['steps'])->toHaveCount(count(Yaml::parse($raw)['jobs'][$jobId]['steps']) + 1);
        expect(workflowRuleIds(workflowHardeningViolations($path, $raw, $unsafe)))->toContain('persisted-credentials');
    }
    $workflow = Yaml::parseFile($root.'/.github/workflows/zizmor.yml');
    $run = $workflow['jobs']['zizmor']['steps'][1]['run'];
    $coversPackages = static fn (string $command): bool => str_contains($command, 'packages/core/.github/workflows/')
        && ! str_contains($command, 'packages/filament/.github/workflows/');
    expect($coversPackages($run))->toBeTrue();
    expect($coversPackages(str_replace('packages/core/.github/workflows/', '', $run)))->toBeFalse();
});

it('runs CodeQL, secret-scan, security, zizmor, static-analysis and code-style on the same push branches as the test workflow', function (): void {
    $root = dirname(__DIR__, 2);
    $tests = Yaml::parseFile($root.'/.github/workflows/tests.yml');
    $required = $tests['on']['push']['branches'];
    $covers = static fn (array $actual): bool => array_diff($required, $actual) === [];

    foreach (['codeql.yml', 'secret-scan.yml', 'security.yml', 'zizmor.yml', 'static-analysis.yml', 'code-style.yml'] as $file) {
        $workflow = Yaml::parseFile($root.'/.github/workflows/'.$file);
        $branches = $workflow['on']['push']['branches'];

        expect($covers($branches))->toBeTrue()
            ->and(array_key_exists('workflow_call', $workflow['on']))->toBeTrue();
        foreach (['fix/**', 'hotfix/**'] as $branch) {
            expect($covers(array_values(array_diff($branches, [$branch]))))->toBeFalse();
        }
    }
});

it('isolates tag security runs from cancellation and pending-run replacement', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/security.yml');
    $concurrency = $workflow['concurrency'];
    $expected = 'security-${{ github.ref_type == \'tag\' && github.run_id || github.ref }}';
    $cancel = '${{ github.ref_type == \'branch\' && github.ref != \'refs/heads/main\' }}';

    expect($concurrency['group'])->toBe($expected)
        ->and($concurrency['cancel-in-progress'])->toBe($cancel)
        ->and(str_replace('github.run_id', 'github.ref', $concurrency['group']))->not->toBe($expected);
});

it('reports exactly its own rule for the negative fixture', function (string $rule): void {
    $violations = workflowFileViolations(__DIR__.'/Fixtures/Workflows/'.$rule.'.yml');

    expect(workflowRuleIds($violations))->toBe([$rule]);
})->with([
    'unpinned-action',
    'continue-on-error',
    'masked-failure',
    'persisted-credentials',
    'top-level-permissions',
    'write-permission',
    'dangerous-trigger',
    'secrets-inherit',
    'strict-shell',
    'untrusted-interpolation',
]);

it('has one fixture per rule and no other', function (): void {
    $fixtures = array_map(static fn (string $path): string => basename($path, '.yml'), glob(__DIR__.'/Fixtures/Workflows/*.yml') ?: []);

    expect($fixtures)->toHaveCount(10);
});

it('accepts a version comment with or without the v prefix and rejects a missing one', function (): void {
    $sha = str_repeat('a', 40);
    $check = fn (string $uses): array => workflowRuleIds(workflowHardeningViolations('x.yml', "jobs:\n  a:\n    steps:\n      - $uses\n", ['permissions' => [], 'defaults' => ['run' => ['shell' => 'bash']]]));

    expect($check("uses: shivammathur/setup-php@$sha # 2.40.0"))->toBe([])
        ->and($check("uses: actions/checkout@$sha # v6.1.0"))->toBe([])
        ->and($check("uses: actions/checkout@$sha"))->toBe(['unpinned-action'])
        ->and($check('uses: actions/checkout@'.substr($sha, 0, 39).' # v6'))->toBe(['unpinned-action'])
        ->and($check('uses: ./.github/actions/local'))->toBe([])
        ->and($check('uses: $/.github/workflows/docs.yml'))->toBe([])
        ->and($check('uses: $/owner/repo@main'))->toBe(['unpinned-action']);
});

it('allows only the listed continue-on-error step and the listed write permissions', function (): void {
    $base = ['permissions' => [], 'defaults' => ['run' => ['shell' => 'bash']]];
    $coverage = fn (string $file, string $job, string $step): array => workflowRuleIds(workflowHardeningViolations($file, '', $base + ['jobs' => [$job => ['steps' => [['name' => $step, 'continue-on-error' => true]]]]]));
    $write = fn (string $file, string $job, array $permissions): array => workflowRuleIds(workflowHardeningViolations($file, '', $base + ['jobs' => [$job => ['permissions' => $permissions]]]));

    expect($coverage('tests.yml', 'coverage', 'Upload coverage to Codecov'))->toBe([])
        ->and($coverage('tests.yml', 'coverage', 'Run tests'))->toBe(['continue-on-error'])
        ->and($coverage('tests.yml', 'test', 'Upload coverage to Codecov'))->toBe(['continue-on-error'])
        ->and($write('release.yml', 'release', ['contents' => 'write']))->toBe([])
        ->and($write('release.yml', 'release', ['packages' => 'write']))->toBe(['write-permission'])
        ->and($write('release.yml', 'provenance', ['id-token' => 'write', 'attestations' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/core/.github/workflows/provenance.yml', 'provenance', ['id-token' => 'write', 'attestations' => 'write']))->toBe([])
        ->and($write('packages/core/.github/workflows/provenance.yml', 'relay-final-artifacts', ['id-token' => 'write', 'attestations' => 'write']))->toBe([])
        ->and($write('packages/core/.github/workflows/attestation-setup.yml', 'probe', ['id-token' => 'write', 'attestations' => 'write']))->toBe([])
        ->and($write('packages/filament/.github/workflows/provenance.yml', 'relay-final-artifacts', ['id-token' => 'write', 'attestations' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/filament/.github/workflows/attestation-setup.yml', 'probe', ['id-token' => 'write', 'attestations' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/core/.github/workflows/attestation-setup.yml', 'other', ['id-token' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/another/.github/workflows/attestation-setup.yml', 'probe', ['id-token' => 'write']))->toBe(['write-permission'])
        ->and($write('attestation-setup.yml', 'probe', ['id-token' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/core/.github/workflows/attestation-setup.yml', 'probe', ['artifact-metadata' => 'write']))->toBe(['write-permission'])
        ->and($write('packages/core/.github/workflows/provenance.yml', 'other', ['id-token' => 'write']))->toBe(['write-permission'])
        ->and($write('release.yml', 'artifacts', ['id-token' => 'write']))->toBe(['write-permission'])
        ->and($write('release.yml', 'other', ['contents' => 'write']))->toBe(['write-permission'])
        ->and($write('pr-check.yml', 'size', ['pull-requests' => 'write']))->toBe([])
        ->and($write('pr-check.yml', 'size', ['contents' => 'write']))->toBe(['write-permission'])
        ->and($write('pr-check.yml', 'title', ['pull-requests' => 'write']))->toBe(['write-permission'])
        ->and($write('release.yml', 'release', ['contents' => 'read']))->toBe([]);
});

it('requires an owner, a reason and a future expiry within 180 days on every zizmor ignore entry', function (): void {
    $today = new DateTimeImmutable('2026-10-17');
    $entry = fn (string $comment): string => "rules:\n  template-injection:\n    ignore:\n$comment      - \"tests.yml:10\"\n";

    expect(zizmorAllowlistViolations((string) file_get_contents(dirname(__DIR__, 2).'/.github/zizmor.yml'), new DateTimeImmutable('today', new DateTimeZone('UTC'))))->toBe([])
        ->and(zizmorAllowlistViolations($entry("      # owner: @academici; reason: upstream bug; expires: 2026-12-01\n"), $today))->toBe([])
        ->and(zizmorAllowlistViolations($entry("      # owner: @academici; reason: upstream bug; expires: 2026-10-01\n"), $today))->toBe(['allowlist-expired line 5'])
        ->and(zizmorAllowlistViolations($entry("      # owner: @academici; reason: upstream bug; expires: 2027-12-01\n"), $today))->toBe(['allowlist-too-long line 5'])
        ->and(zizmorAllowlistViolations($entry(''), $today))->toBe(['allowlist-unannotated line 4'])
        ->and(zizmorAllowlistViolations($entry("      # reason: upstream bug\n"), $today))->toBe(['allowlist-unannotated line 5'])
        ->and(zizmorAllowlistViolations("rules:\n  template-injection:\n    disable: true\n", $today))->toBe(['allowlist-disabled-rule line 3'])
        ->and(zizmorAllowlistViolations("rules:\n  template-injection:\n    ignore:\n      # owner: @academici; reason: x; expires: 2026-12-01\n      - \"tests.yml\"\n", $today))->toBe(['allowlist-wholesale line 5']);
});

it('rejects a modified privileged dependabot workflow', function (): void {
    $raw = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/dependabot-auto-merge.yml');
    $unsafe = str_replace('Disable Dependabot auto-merge', 'Disable Dependabot auto-merge changed', $raw);
    expect(workflowRuleIds(workflowHardeningViolations('dependabot-auto-merge.yml', $unsafe, Yaml::parse($unsafe))))->toContain('dangerous-trigger');
});
