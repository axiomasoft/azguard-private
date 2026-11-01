<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * Release gating (conditions 9 and 10 of the 1.0 release gate, knowledge/p4-execution#release-workflow): a GitHub
 * release and the package split happen only for a tag whose commit passed, in the same run, every qualification
 * workflow and the compatibility comparison. The workflows are parsed and checked by one function; each negative case
 * removes or changes one key of the parsed arrays and expects that function to report it.
 */

/** The workflows release.yml calls, by job id. */
const RELEASE_CALLED = [
    'tests' => 'tests.yml',
    'static' => 'static-analysis.yml',
    'style' => 'code-style.yml',
    'docs' => 'docs.yml',
    'nightly' => 'tests-nightly.yml',
    'compat' => 'compatibility.yml',
    'security' => 'security.yml',
    'codeql' => 'codeql.yml',
    'zizmor' => 'zizmor.yml',
    'secret-scan' => 'secret-scan.yml',
    'workers' => 'workers.yml',
];

/** The consumer-archive steps of `validate` and of the tests.yml consumer job: default, Filament, core-only, relocation. */
const RELEASE_CONSUMER_MODES = ['default' => '/consumer-fixture\.sh (?!core-only|relocated-audit)(?!.*--with-filament)/', 'filament' => '/consumer-fixture\.sh .*--with-filament/', 'core-only' => '/consumer-fixture\.sh core-only/', 'relocated-audit' => '/consumer-fixture\.sh relocated-audit/'];

/** The only step allowed to continue on error: an upload, not a check. */
const RELEASE_CONTINUE_ON_ERROR = ['tests.yml' => ['coverage' => ['Upload coverage to Codecov']]];

/** @return array<string, array<mixed>> the parsed workflows the release graph consists of, by file name */
function releaseWorkflows(): array
{
    $workflows = [];

    foreach (['release.yml', 'split.yml', 'docs-deploy.yml', ...array_values(RELEASE_CALLED)] as $file) {
        $workflows[$file] = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$file);
    }

    return $workflows;
}

/**
 * The triggers of a workflow. A YAML 1.1 reader turns the key `on` into `true`; Symfony's 1.2 reader keeps `on`.
 *
 * @param  array<mixed>  $workflow
 * @return array<string, mixed>
 */
function releaseTriggers(array $workflow): array
{
    $on = $workflow['on'] ?? $workflow[true] ?? [];

    return is_array($on) ? $on : [(string) $on => null];
}

/**
 * @param  array<mixed>  $job
 * @return list<array<string, mixed>>
 */
function releaseSteps(array $job): array
{
    return array_values(array_filter($job['steps'] ?? [], is_array(...)));
}

/** @param array<mixed> $job */
function releaseRuns(array $job): string
{
    return implode("\n", array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), releaseSteps($job)));
}

function releaseSameCommitWorkflow(mixed $uses, string $file): bool
{
    return in_array($uses, ['./.github/workflows/'.$file, '$/.github/workflows/'.$file], true);
}

/**
 * Every violation of the release-gating model in a set of parsed workflows; an empty list when the model holds.
 *
 * @param  array<string, array<mixed>>  $workflows
 * @return list<string>
 */
function releaseViolations(array $workflows): array
{
    $violations = [];
    $release = $workflows['release.yml'] ?? [];
    $jobs = $release['jobs'] ?? [];
    $gates = ['validate', 'validate-consumers', 'artifacts', 'advisories', ...array_keys(RELEASE_CALLED)];

    // Bypass closed: only tags trigger a release, the split only by a call.
    $triggers = releaseTriggers($release);

    if (array_keys($triggers) !== ['push'] || array_keys($triggers['push'] ?? []) !== ['tags']) {
        $violations[] = 'release.yml: only a tag push may trigger a release';
    }

    if (($triggers['push']['tags'] ?? []) !== ['v[0-9]*.[0-9]*.[0-9]*', 'v[0-9]*.[0-9]*.[0-9]*-*']) {
        $violations[] = 'release.yml: stable and pre-release tags run the same graph';
    }

    if (($release['concurrency'] ?? null) !== ['group' => 'release-${{ github.ref }}', 'cancel-in-progress' => false]) {
        $violations[] = 'release.yml: releases need a separate non-cancelling concurrency group';
    }

    if (($workflows['tests.yml']['concurrency']['cancel-in-progress'] ?? null) !== '${{ github.ref != \'refs/heads/main\' && !startsWith(github.ref, \'refs/tags/\') }}') {
        $violations[] = 'tests.yml: tag runs must not cancel each other';
    }

    if (($workflows['compatibility.yml']['concurrency'] ?? null) !== [
        'group' => 'compatibility-${{ github.ref_type == \'tag\' && github.run_id || github.ref }}',
        'cancel-in-progress' => '${{ github.ref_type == \'branch\' && github.ref != \'refs/heads/main\' }}',
    ]) {
        $violations[] = 'compatibility.yml: tag runs must have separate non-cancelling groups';
    }

    foreach (['test', 'test-db-matrix'] as $matrixJob) {
        $steps = releaseSteps($workflows['tests.yml']['jobs'][$matrixJob] ?? []);
        $runs = array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), $steps);
        $resolve = array_search(true, array_map(static fn (string $run): bool => str_contains($run, 'composer update '), $runs), true);
        $audit = array_search('composer audit --locked --abandoned=fail', $runs, true);
        $osv = array_search(true, array_map(static fn (string $run): bool => str_contains($run, 'bash bin/security-tools.sh osv-scanner -- scan source --lockfile=composer.lock --config=.github/osv-scanner.toml') && str_contains($run, 'sha256sum composer.lock > "$RUNNER_TEMP/audited-lock.sha256"') && str_contains($run, 'sha256sum --check "$RUNNER_TEMP/audited-lock.sha256"'), $runs), true);
        $row = array_search(true, array_map(static fn (string $run): bool => str_contains($run, 'matrix-row/row.json'), $runs), true);

        if ($resolve === false || $audit === false || $osv === false || $row === false || $resolve >= $audit || $audit >= $osv || $osv >= $row || ! str_contains($runs[$row], 'sha256sum --check "$RUNNER_TEMP/audited-lock.sha256"')) {
            $violations[] = "tests.yml: {$matrixJob} must audit its resolved lock before recording success";
        }
    }

    foreach (['validate', 'validate-consumers', 'artifacts', 'release'] as $job) {
        $checkouts = array_values(array_filter(releaseSteps($jobs[$job] ?? []), static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')));

        if (count($checkouts) !== 1 || ($checkouts[0]['with']['ref'] ?? null) !== '${{ github.sha }}') {
            $violations[] = "release.yml: {$job} checkout must use the candidate SHA";
        }
    }

    if (array_keys(releaseTriggers($workflows['split.yml'] ?? [])) !== ['workflow_call']) {
        $violations[] = 'split.yml: only release.yml may call the split';
    }

    // Every qualification workflow is called on the tag.
    foreach (RELEASE_CALLED as $job => $file) {
        if (! array_key_exists('workflow_call', releaseTriggers($workflows[$file] ?? []))) {
            $violations[] = "{$file}: no workflow_call trigger";
        }

        if (! releaseSameCommitWorkflow($jobs[$job]['uses'] ?? null, $file)) {
            $violations[] = "release.yml: job {$job} does not call {$file}";
        }
    }

    // The split and the release need the whole graph.
    $needs = static function (string $job) use ($jobs): array {
        $list = (array) ($jobs[$job]['needs'] ?? []);
        sort($list);

        return $list;
    };
    $expected = $gates;
    sort($expected);

    if ($needs('gate') !== $expected || ($jobs['gate']['if'] ?? null) !== 'always()'
        || ! str_contains(releaseRuns($jobs['gate'] ?? []), 'bin/release-gate.php')) {
        $violations[] = 'release.yml: gate must always evaluate every prerequisite';
    }
    $expected[] = 'gate';
    sort($expected);

    if ($needs('split') !== $expected || ! releaseSameCommitWorkflow($jobs['split']['uses'] ?? null, 'split.yml')) {
        $violations[] = 'release.yml: split must call split.yml and need '.implode(', ', $expected);
    }

    if ($needs('provenance') !== ['gate', 'split']) {
        $violations[] = 'release.yml: provenance must follow gate and split';
    }
    $splitSteps = releaseSteps($workflows['split.yml']['jobs']['split'] ?? []);
    $publish = $jobs['publish-packages'] ?? [];
    $publishSteps = releaseSteps($publish);

    if ($needs('publish-packages') !== ['gate', 'provenance', 'split']
        || isset($publish['if'])
        || str_contains(releaseRuns($workflows['split.yml']['jobs']['split'] ?? []), 'refs/tags/')
        || array_filter($splitSteps, static fn (array $step): bool => isset($step['with']['tag'])) !== []
        || ($jobs['provenance']['outputs']['core_sha'] ?? null) !== '${{ steps.verify.outputs.core_sha }}'
        || ($jobs['provenance']['outputs']['filament_sha'] ?? null) !== '${{ steps.verify.outputs.filament_sha }}'
        || ($publishSteps[0]['env']['CORE_SHA'] ?? null) !== '${{ needs.provenance.outputs.core_sha }}'
        || ($publishSteps[0]['env']['FILAMENT_SHA'] ?? null) !== '${{ needs.provenance.outputs.filament_sha }}') {
        $violations[] = 'release.yml: package tags must follow verified provenance';
    }
    $expected[] = 'split';
    $expected[] = 'provenance';
    $expected[] = 'publish-packages';
    sort($expected);

    if ($needs('release') !== $expected) {
        $violations[] = 'release.yml: release must need '.implode(', ', $expected);
    }

    // validate: no duplicate of a called workflow, the four consumer archives, the changelog preflight.
    $validate = releaseRuns($jobs['validate'] ?? []);

    if (preg_match('/composer (analyse|lint|test)\b|vendor\/bin\/(pest|phpstan|pint)/', $validate) === 1) {
        $violations[] = 'release.yml: validate repeats a called workflow';
    }
    foreach (['release.yml validate-consumers' => $jobs['validate-consumers'] ?? [], 'tests.yml consumer-fixture' => $workflows['tests.yml']['jobs']['consumer-fixture'] ?? []] as $where => $job) {
        $runs = releaseRuns($job);

        if (($job['strategy']['matrix']['laravel'] ?? null) !== [12, 13]) {
            $violations[] = "{$where}: the consumer archives must run on Laravel 12 and 13";
        }

        foreach (RELEASE_CONSUMER_MODES as $mode => $pattern) {
            if (preg_match($pattern, $runs) !== 1) {
                $violations[] = "{$where}: no {$mode} consumer archive step";
            }
        }

        if (str_contains($runs, '--laravel=13') || str_contains($runs, '--laravel=12')) {
            $violations[] = "{$where}: the Laravel major must come from the matrix";
        }
    }

    if (! str_contains($validate, 'bin/release-preflight.sh')) {
        $violations[] = 'release.yml: validate does not run the release preflight';
    }

    // Tag guards: annotated, reachable from main, the C+1 rule; all before the changelog preflight.
    $validateSteps = releaseSteps($jobs['validate'] ?? []);
    $stepNames = array_map(static fn (array $step): string => (string) ($step['name'] ?? ''), $validateSteps);
    $position = static function (string $needle) use ($stepNames): ?int {
        foreach ($stepNames as $index => $name) {
            if (str_contains($name, $needle)) {
                return $index;
            }
        }

        return null;
    };
    $changelog = $position('changelog section');

    foreach (['annotated tag', 'reachable from main', 'first stable tag'] as $guard) {
        $at = $position($guard);

        if ($at === null) {
            $violations[] = "release.yml: validate has no guard for {$guard}";
        } elseif ($changelog !== null && $at > $changelog) {
            $violations[] = "release.yml: validate runs the {$guard} guard after the changelog preflight";
        }
    }

    // Build first; verify the split mapping before attesting the final artifacts.
    $artifacts = $jobs['artifacts'] ?? [];
    $artifactUses = implode("\n", array_map(static fn (array $step): string => (string) ($step['uses'] ?? ''), releaseSteps($artifacts)));
    $provenance = $jobs['provenance'] ?? [];

    if (($provenance['timeout-minutes'] ?? 0) < 60) {
        $violations[] = 'release.yml: provenance timeout must cover all bounded attestation waits';
    }
    $permissions = $provenance['permissions'] ?? [];
    ksort($permissions);

    if ($permissions !== ['contents' => 'read']) {
        $violations[] = 'release.yml: provenance must have contents: read only';
    }

    if (! str_contains(releaseRuns($artifacts), 'bin/release-artifacts.sh')) {
        $violations[] = 'release.yml: artifacts does not run bin/release-artifacts.sh';
    }

    $provenanceSteps = releaseSteps($provenance);
    $verify = array_search(true, array_map(static fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'bin/verify-release-split.sh'), $provenanceSteps), true);

    if ($verify === false || ! str_contains((string) ($provenanceSteps[$verify]['run'] ?? ''), '--dispatch-final')) {
        $violations[] = 'release.yml: split verification must dispatch and verify final attestations';
    }

    if (! str_contains($artifactUses, 'actions/upload-artifact@')) {
        $violations[] = 'release.yml: artifacts does not upload the files';
    }
    $releaseSteps = releaseSteps($jobs['release'] ?? []);
    $attached = implode("\n", array_map(static fn (array $step): string => (string) ($step['with']['files'] ?? ''), $releaseSteps));

    if (! str_contains(implode("\n", array_map(static fn (array $step): string => (string) ($step['uses'] ?? ''), $releaseSteps)), 'actions/download-artifact@')
        || ! str_contains($attached, 'dist/*.zip') || ! str_contains($attached, 'dist/*.cdx.json') || ! str_contains($attached, 'dist/SHA256SUMS')) {
        $violations[] = 'release.yml: release does not attach the archives, the SBOMs and SHA256SUMS';
    }

    // Least privilege: write access for the release job alone.
    if (($release['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'release.yml: the workflow permissions must be contents: read';
    }
    foreach ($jobs as $id => $job) {
        $writes = array_keys(array_filter((array) ($job['permissions'] ?? []), static fn (mixed $level): bool => $level === 'write'));
        $allowed = match ($id) {
            'release' => ['contents'],
            'codeql' => ['security-events'],
            default => [],
        };

        if (array_diff($writes, $allowed) !== [] || ! isset($job['permissions'])) {
            $violations[] = "release.yml: job {$id} has no least-privilege permissions";
        }
    }

    // The call grants only contents: read; the deploy lives in docs-deploy.yml.
    if (($jobs['docs']['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'release.yml: the docs call must grant only contents: read';
    }

    if (array_key_exists('deploy', $workflows['docs.yml']['jobs'] ?? [])) {
        $violations[] = 'docs.yml: must not deploy';
    }
    $deployPermissions = $workflows['docs-deploy.yml']['jobs']['deploy']['permissions'] ?? [];
    ksort($deployPermissions);

    if ($deployPermissions !== ['id-token' => 'write', 'pages' => 'write']
        || ($workflows['docs-deploy.yml']['permissions'] ?? null) !== ['contents' => 'read']
        || ! releaseSameCommitWorkflow($workflows['docs-deploy.yml']['jobs']['build']['uses'] ?? null, 'docs.yml')) {
        $violations[] = 'docs-deploy.yml: the deploy must be the only Pages writer, built through docs.yml';
    }

    // The comparison: full history and tags, freshness before compatibility, the label only through env.
    $compare = $workflows['compatibility.yml']['jobs']['compare'] ?? [];
    $checkout = array_values(array_filter(releaseSteps($compare), static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')))[0] ?? [];

    if (($checkout['with']['fetch-depth'] ?? null) !== 0 || ($checkout['with']['fetch-tags'] ?? null) !== true) {
        $violations[] = 'compatibility.yml: the checkout must fetch the whole history and the tags';
    }
    $runs = array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), releaseSteps($compare));
    $check = array_search('composer api:check', $runs, true);
    $compat = array_search('composer api:compat', $runs, true);

    if ($check === false || $compat === false || $check > $compat) {
        $violations[] = 'compatibility.yml: composer api:check must run before composer api:compat';
    }
    $guard = array_values(array_filter(releaseSteps($compare), static fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'bin/compat-baseline-guard.sh')))[0] ?? [];

    if (($guard['env']['LABELS'] ?? null) !== '${{ toJSON(github.event.pull_request.labels.*.name) }}'
        || str_contains((string) ($guard['run'] ?? ''), '${{') || ! str_contains((string) ($guard['run'] ?? ''), '"$LABELS"')
        || ($guard['if'] ?? null) !== "github.event_name == 'pull_request'") {
        $violations[] = 'compatibility.yml: the pull-request labels must reach the guard only through env';
    }
    foreach (['push', 'pull_request', 'workflow_call'] as $trigger) {
        if (! array_key_exists($trigger, releaseTriggers($workflows['compatibility.yml'] ?? []))) {
            $violations[] = "compatibility.yml: no {$trigger} trigger";
        }
    }

    // No check may pass by continuing on error.
    foreach ($workflows as $file => $workflow) {
        foreach ($workflow['jobs'] ?? [] as $id => $job) {
            if (array_key_exists('continue-on-error', $job)) {
                $violations[] = "{$file}: job {$id} continues on error";
            }
            foreach (releaseSteps($job) as $step) {
                if (array_key_exists('continue-on-error', $step) && ! in_array($step['name'] ?? null, RELEASE_CONTINUE_ON_ERROR[$file][$id] ?? [], true)) {
                    $violations[] = "{$file}: step '".($step['name'] ?? '?')."' of {$id} continues on error";
                }
            }
        }
    }

    return $violations;
}

it('holds the release-gating model', function (): void {
    expect(releaseViolations(releaseWorkflows()))->toBe([]);
});

it('audits the production lock before creating the SBOM', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/bin/release-artifacts.sh');
    $valid = static function (string $script): bool {
        $resolve = strpos($script, 'composer update --no-dev --no-scripts --no-plugins --no-interaction --no-progress');
        $fingerprint = strpos($script, 'sha256sum "${project}/composer.lock" > "${work}/sbom-${package}.lock.sha256"');
        $audit = strpos($script, 'composer audit --locked --no-dev --abandoned=fail --no-interaction');
        $osv = strpos($script, 'osv-scanner -- scan source --lockfile="${project}/composer.lock"');
        $scan = strpos($script, 'syft --');
        $check = 'sha256sum --check "${work}/sbom-${package}.lock.sha256"';
        $beforeScan = $osv === false ? false : strpos($script, $check, $osv);
        $afterScan = $scan === false ? false : strpos($script, $check, $scan);

        return $resolve !== false && $fingerprint !== false && $audit !== false && $osv !== false && $scan !== false
            && $beforeScan !== false && $afterScan !== false
            && $resolve < $fingerprint && $fingerprint < $audit && $audit < $osv && $osv < $beforeScan
            && $beforeScan < $scan && $scan < $afterScan;
    };

    expect($valid($script))->toBeTrue()
        ->and($valid(str_replace('composer audit --locked --no-dev --abandoned=fail --no-interaction', 'true', $script)))->toBeFalse()
        ->and($valid(str_replace('osv-scanner -- scan source --lockfile="${project}/composer.lock"', 'true', $script)))->toBeFalse()
        ->and($valid(str_replace('sha256sum --check "${work}/sbom-${package}.lock.sha256"', 'true', $script)))->toBeFalse();
});

it('accepts only the two same-commit forms of the expected workflow', function (): void {
    expect(releaseSameCommitWorkflow('./.github/workflows/docs.yml', 'docs.yml'))->toBeTrue()
        ->and(releaseSameCommitWorkflow('$/.github/workflows/docs.yml', 'docs.yml'))->toBeTrue()
        ->and(releaseSameCommitWorkflow('owner/repo/.github/workflows/docs.yml@main', 'docs.yml'))->toBeFalse()
        ->and(releaseSameCommitWorkflow('$/.github/workflows/other.yml', 'docs.yml'))->toBeFalse();
});

it('matches stable and prerelease tags using glob syntax', function (): void {
    $patterns = releaseTriggers(releaseWorkflows()['release.yml'])['push']['tags'];
    foreach (['v2.0.0', 'v2.0.0-rc.1', 'v12.34.56'] as $tag) {
        expect(array_filter($patterns, static fn (string $pattern): bool => fnmatch($pattern, $tag)))->not->toBeEmpty();
    }
});

it('rejects a moved tag even if it is annotated', function (): void {
    $dir = sys_get_temp_dir().'/azguard-release-tag-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $run = static function (array $command, array $env = []) use ($dir): Process {
        $process = new Process($command, $dir, $env);
        $process->run();

        return $process;
    };

    try {
        expect($run(['git', 'init', '-q'])->isSuccessful())->toBeTrue();
        expect($run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--allow-empty', '-qm', 'candidate'])->isSuccessful())->toBeTrue();
        $sha = trim($run(['git', 'rev-parse', 'HEAD'])->getOutput());
        expect($run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'tag', '-a', 'v1.2.3', '-m', 'release'])->isSuccessful())->toBeTrue();
        $steps = releaseSteps(releaseWorkflows()['release.yml']['jobs']['validate']);
        $guard = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? '') === 'Require an annotated tag'))[0]['run'];

        expect($run(['bash', '-c', $guard], ['TAG' => 'v1.2.3', 'TAGGED_SHA' => $sha])->isSuccessful())->toBeTrue();
        $mismatch = $run(['bash', '-c', $guard], ['TAG' => 'v1.2.3', 'TAGGED_SHA' => str_repeat('a', 40)]);
        expect($mismatch->isSuccessful())->toBeFalse()
            ->and($mismatch->getOutput())->toContain('does not resolve to the candidate SHA');
    } finally {
        $run(['rm', '-rf', '--', $dir]);
    }
});

it('requires every scanner in the release graph and restricts its permissions', function (string $scanner): void {
    $workflows = releaseWorkflows();
    $workflows['release.yml']['jobs']['release']['needs'] = array_values(array_diff($workflows['release.yml']['jobs']['release']['needs'], [$scanner]));
    expect(implode("\n", releaseViolations($workflows)))->toContain('release.yml: release must need');

    $workflows = releaseWorkflows();
    $workflows['release.yml']['jobs'][$scanner]['permissions']['contents'] = 'write';
    expect(releaseViolations($workflows))->toContain("release.yml: job {$scanner} has no least-privilege permissions");
})->with(['codeql', 'zizmor', 'secret-scan']);

it('keeps the Codecov upload as the only step that continues on error', function (): void {
    $steps = releaseSteps(releaseWorkflows()['tests.yml']['jobs']['coverage']);
    $upload = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? null) === 'Upload coverage to Codecov'))[0];

    expect($upload['continue-on-error'])->toBeTrue()
        ->and($upload['uses'])->toStartWith('codecov/codecov-action@');
});

it('reports a broken release graph', function (Closure $break, string $violation): void {
    $workflows = $break(releaseWorkflows());

    expect(releaseViolations($workflows))->toContain($violation);
})->with([
    'cancelling release runs' => [static function (array $w): array {
        $w['release.yml']['concurrency']['cancel-in-progress'] = true;

        return $w;
    }, 'release.yml: releases need a separate non-cancelling concurrency group'],
    'cancelling tag tests' => [static function (array $w): array {
        $w['tests.yml']['concurrency']['cancel-in-progress'] = '${{ github.ref != \'refs/heads/main\' }}';

        return $w;
    }, 'tests.yml: tag runs must not cancel each other'],
    'cancelling tag compatibility' => [static function (array $w): array {
        $w['compatibility.yml']['concurrency']['cancel-in-progress'] = '${{ github.ref != \'refs/heads/main\' }}';

        return $w;
    }, 'compatibility.yml: tag runs must have separate non-cancelling groups'],
    'provenance timeout shorter than the waits' => [static function (array $w): array {
        $w['release.yml']['jobs']['provenance']['timeout-minutes'] = 30;

        return $w;
    }, 'release.yml: provenance timeout must cover all bounded attestation waits'],
    'missing matrix OSV scan' => [static function (array $w): array {
        $w['tests.yml']['jobs']['test']['steps'] = array_values(array_filter($w['tests.yml']['jobs']['test']['steps'], static fn (array $step): bool => ($step['name'] ?? '') !== 'Scan resolved lock with OSV'));

        return $w;
    }, 'tests.yml: test must audit its resolved lock before recording success'],
    'matrix lock changes after OSV scan' => [static function (array $w): array {
        foreach ($w['tests.yml']['jobs']['test']['steps'] as &$step) {
            if (($step['name'] ?? '') === 'Record successful matrix row') {
                $step['run'] = str_replace('sha256sum --check "$RUNNER_TEMP/audited-lock.sha256"', 'true', $step['run']);
            }
        }

        return $w;
    }, 'tests.yml: test must audit its resolved lock before recording success'],
    'unaudited matrix lock' => [static function (array $w): array {
        $w['tests.yml']['jobs']['test']['steps'] = array_values(array_filter($w['tests.yml']['jobs']['test']['steps'], static fn (array $step): bool => ($step['name'] ?? '') !== 'Audit resolved dependencies'));

        return $w;
    }, 'tests.yml: test must audit its resolved lock before recording success'],
    'unaudited database matrix lock' => [static function (array $w): array {
        $w['tests.yml']['jobs']['test-db-matrix']['steps'] = array_values(array_filter($w['tests.yml']['jobs']['test-db-matrix']['steps'], static fn (array $step): bool => ($step['name'] ?? '') !== 'Audit resolved dependencies'));

        return $w;
    }, 'tests.yml: test-db-matrix must audit its resolved lock before recording success'],
    'matrix audit before resolution' => [static function (array $w): array {
        $steps = &$w['tests.yml']['jobs']['test']['steps'];
        $audit = array_search('Audit resolved dependencies', array_column($steps, 'name'), true);
        $step = $steps[$audit];
        array_splice($steps, $audit, 1);
        array_unshift($steps, $step);

        return $w;
    }, 'tests.yml: test must audit its resolved lock before recording success'],
    'release checkout follows a moved tag' => [static function (array $w): array {
        unset($w['release.yml']['jobs']['release']['steps'][0]['with']['ref']);

        return $w;
    }, 'release.yml: release checkout must use the candidate SHA'],
    'split without the compatibility gate' => [static function (array $w): array {
        $w['release.yml']['jobs']['split']['needs'] = array_values(array_diff($w['release.yml']['jobs']['split']['needs'], ['compat']));

        return $w;
    }, 'release.yml: split must call split.yml and need advisories, artifacts, codeql, compat, docs, gate, nightly, secret-scan, security, static, style, tests, validate, validate-consumers, workers, zizmor'],
    'release without the nightly gate' => [static function (array $w): array {
        $w['release.yml']['jobs']['release']['needs'] = array_values(array_diff($w['release.yml']['jobs']['release']['needs'], ['nightly']));

        return $w;
    }, 'release.yml: release must need advisories, artifacts, codeql, compat, docs, gate, nightly, provenance, publish-packages, secret-scan, security, split, static, style, tests, validate, validate-consumers, workers, zizmor'],
    'release without the split' => [static function (array $w): array {
        $w['release.yml']['jobs']['release']['needs'] = ['validate', 'validate-consumers', 'tests', 'static', 'style', 'docs', 'nightly', 'compat', 'security'];

        return $w;
    }, 'release.yml: release must need advisories, artifacts, codeql, compat, docs, gate, nightly, provenance, publish-packages, secret-scan, security, split, static, style, tests, validate, validate-consumers, workers, zizmor'],
    'package tags before provenance' => [static function (array $w): array {
        $w['release.yml']['jobs']['publish-packages']['needs'] = ['gate', 'split'];

        return $w;
    }, 'release.yml: package tags must follow verified provenance'],
    'split publishes a tag early' => [static function (array $w): array {
        $w['split.yml']['jobs']['split']['steps'][] = ['run' => 'git push origin HEAD:refs/tags/v1.0.0'];

        return $w;
    }, 'release.yml: package tags must follow verified provenance'],
    'split action publishes a tag early' => [static function (array $w): array {
        $w['split.yml']['jobs']['split']['steps'][] = ['uses' => 'example/action@'.str_repeat('a', 40), 'with' => ['tag' => '${{ github.ref_name }}']];

        return $w;
    }, 'release.yml: package tags must follow verified provenance'],
    'package publication ignores verified SHA' => [static function (array $w): array {
        $w['release.yml']['jobs']['publish-packages']['steps'][0]['env']['CORE_SHA'] = '${{ github.sha }}';

        return $w;
    }, 'release.yml: package tags must follow verified provenance'],
    'gate skipped on failure' => [static function (array $w): array {
        unset($w['release.yml']['jobs']['gate']['if']);

        return $w;
    }, 'release.yml: gate must always evaluate every prerequisite'],
    'gate missing scanner dependency' => [static function (array $w): array {
        $w['release.yml']['jobs']['gate']['needs'] = array_values(array_diff($w['release.yml']['jobs']['gate']['needs'], ['secret-scan']));

        return $w;
    }, 'release.yml: gate must always evaluate every prerequisite'],
    'a called workflow without workflow_call' => [static function (array $w): array {
        unset($w['tests-nightly.yml']['on']['workflow_call']);

        return $w;
    }, 'tests-nightly.yml: no workflow_call trigger'],
    'a gate job not calling its workflow' => [static function (array $w): array {
        unset($w['release.yml']['jobs']['docs']);

        return $w;
    }, 'release.yml: job docs does not call docs.yml'],
    'a manual release trigger' => [static function (array $w): array {
        $w['release.yml']['on']['workflow_dispatch'] = null;

        return $w;
    }, 'release.yml: only a tag push may trigger a release'],
    'a split trigger besides the call' => [static function (array $w): array {
        $w['split.yml']['on']['workflow_dispatch'] = null;

        return $w;
    }, 'split.yml: only release.yml may call the split'],
    'pre-release tags left out' => [static function (array $w): array {
        $w['release.yml']['on']['push']['tags'] = ['v[0-9]*.[0-9]*.[0-9]*'];

        return $w;
    }, 'release.yml: stable and pre-release tags run the same graph'],
    'validate repeating the tests' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate']['steps'][] = ['name' => 'Tests', 'run' => 'composer test -- --ci'];

        return $w;
    }, 'release.yml: validate repeats a called workflow'],
    'validate-consumers without the relocation rehearsal' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate-consumers']['steps'] = array_values(array_filter($w['release.yml']['jobs']['validate-consumers']['steps'], static fn (array $s): bool => ! str_contains((string) ($s['run'] ?? ''), 'relocated-audit')));

        return $w;
    }, 'release.yml validate-consumers: no relocated-audit consumer archive step'],
    'validate-consumers on Laravel 13 only' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate-consumers']['strategy']['matrix']['laravel'] = [13];

        return $w;
    }, 'release.yml validate-consumers: the consumer archives must run on Laravel 12 and 13'],
    'tests.yml consumer job on Laravel 13 only' => [static function (array $w): array {
        $w['tests.yml']['jobs']['consumer-fixture']['strategy']['matrix']['laravel'] = [13];

        return $w;
    }, 'tests.yml consumer-fixture: the consumer archives must run on Laravel 12 and 13'],
    'tests.yml consumer job without the relocation rehearsal' => [static function (array $w): array {
        $w['tests.yml']['jobs']['consumer-fixture']['steps'] = array_values(array_filter($w['tests.yml']['jobs']['consumer-fixture']['steps'], static fn (array $s): bool => ! str_contains((string) ($s['run'] ?? ''), 'relocated-audit')));

        return $w;
    }, 'tests.yml consumer-fixture: no relocated-audit consumer archive step'],
    'validate-consumers without the core-only archive' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate-consumers']['steps'] = array_values(array_filter($w['release.yml']['jobs']['validate-consumers']['steps'], static fn (array $s): bool => ! str_contains((string) ($s['run'] ?? ''), 'core-only')));

        return $w;
    }, 'release.yml validate-consumers: no core-only consumer archive step'],
    'validate without the annotated tag guard' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate']['steps'] = array_values(array_filter($w['release.yml']['jobs']['validate']['steps'], static fn (array $s): bool => ! str_contains((string) ($s['name'] ?? ''), 'annotated tag')));

        return $w;
    }, 'release.yml: validate has no guard for annotated tag'],
    'validate without the main ancestry guard' => [static function (array $w): array {
        $w['release.yml']['jobs']['validate']['steps'] = array_values(array_filter($w['release.yml']['jobs']['validate']['steps'], static fn (array $s): bool => ! str_contains((string) ($s['name'] ?? ''), 'reachable from main')));

        return $w;
    }, 'release.yml: validate has no guard for reachable from main'],
    'provenance without final attestation dispatch' => [static function (array $w): array {
        foreach ($w['release.yml']['jobs']['provenance']['steps'] as &$step) {
            $step['run'] = str_replace(' --dispatch-final', '', $step['run'] ?? '');
        }

        return $w;
    }, 'release.yml: split verification must dispatch and verify final attestations'],
    'provenance with unnecessary permissions' => [static function (array $w): array {
        $w['release.yml']['jobs']['provenance']['permissions']['attestations'] = 'write';

        return $w;
    }, 'release.yml: provenance must have contents: read only'],
    'release not attaching the SBOMs' => [static function (array $w): array {
        foreach ($w['release.yml']['jobs']['release']['steps'] as &$step) {
            unset($step['with']['files']);
        }

        return $w;
    }, 'release.yml: release does not attach the archives, the SBOMs and SHA256SUMS'],
    'write access for the whole workflow' => [static function (array $w): array {
        $w['release.yml']['permissions'] = ['contents' => 'write'];

        return $w;
    }, 'release.yml: the workflow permissions must be contents: read'],
    'write access for a called gate' => [static function (array $w): array {
        $w['release.yml']['jobs']['tests']['permissions'] = ['contents' => 'write'];

        return $w;
    }, 'release.yml: job tests has no least-privilege permissions'],
    'a job without explicit permissions' => [static function (array $w): array {
        unset($w['release.yml']['jobs']['compat']['permissions']);

        return $w;
    }, 'release.yml: job compat has no least-privilege permissions'],
    'docs call with Pages write' => [static function (array $w): array {
        $w['release.yml']['jobs']['docs']['permissions']['pages'] = 'write';

        return $w;
    }, 'release.yml: the docs call must grant only contents: read'],
    'docs deploying again' => [static function (array $w): array {
        $w['docs.yml']['jobs']['deploy'] = ['runs-on' => 'ubuntu-latest'];

        return $w;
    }, 'docs.yml: must not deploy'],
    'a shallow comparison checkout' => [static function (array $w): array {
        unset($w['compatibility.yml']['jobs']['compare']['steps'][0]['with']['fetch-tags']);

        return $w;
    }, 'compatibility.yml: the checkout must fetch the whole history and the tags'],
    'compatibility before freshness' => [static function (array $w): array {
        $steps = &$w['compatibility.yml']['jobs']['compare']['steps'];
        $runs = array_map(static fn (array $step): ?string => $step['run'] ?? null, $steps);
        $check = array_search('composer api:check', $runs, true);
        $compat = array_search('composer api:compat', $runs, true);
        [$steps[$check], $steps[$compat]] = [$steps[$compat], $steps[$check]];

        return $w;
    }, 'compatibility.yml: composer api:check must run before composer api:compat'],
    'labels interpolated into the command' => [static function (array $w): array {
        foreach ($w['compatibility.yml']['jobs']['compare']['steps'] as $i => $step) {
            if (str_contains((string) ($step['run'] ?? ''), 'compat-baseline-guard')) {
                $w['compatibility.yml']['jobs']['compare']['steps'][$i]['run'] = 'bash bin/compat-baseline-guard.sh "origin/${GITHUB_BASE_REF}" HEAD \'${{ toJSON(github.event.pull_request.labels.*.name) }}\'';
            }
        }

        return $w;
    }, 'compatibility.yml: the pull-request labels must reach the guard only through env'],
    'no comparison on pull requests' => [static function (array $w): array {
        unset($w['compatibility.yml']['on']['pull_request']);

        return $w;
    }, 'compatibility.yml: no pull_request trigger'],
    'a gate that continues on error' => [static function (array $w): array {
        $w['tests.yml']['jobs']['test-db-matrix']['continue-on-error'] = true;

        return $w;
    }, 'tests.yml: job test-db-matrix continues on error'],
    'a check step that continues on error' => [static function (array $w): array {
        $w['compatibility.yml']['jobs']['compare']['steps'][4]['continue-on-error'] = true;

        return $w;
    }, "compatibility.yml: step '".'Compatible with the baseline'."' of compare continues on error"],
]);

it('reads the trigger key the way a YAML 1.1 reader writes it', function (): void {
    expect(array_keys(releaseTriggers([true => ['push' => null, 'workflow_call' => null]])))->toBe(['push', 'workflow_call'])
        ->and(array_keys(releaseTriggers(['on' => 'workflow_call'])))->toBe(['workflow_call']);
});
