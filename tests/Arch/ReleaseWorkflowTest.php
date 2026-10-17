<?php

declare(strict_types=1);

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
    $gates = ['validate', 'validate-consumers', ...array_keys(RELEASE_CALLED)];

    // Bypass closed: only tags trigger a release, the split only by a call.
    $triggers = releaseTriggers($release);

    if (array_keys($triggers) !== ['push'] || array_keys($triggers['push'] ?? []) !== ['tags']) {
        $violations[] = 'release.yml: only a tag push may trigger a release';
    }

    if (($triggers['push']['tags'] ?? []) !== ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']) {
        $violations[] = 'release.yml: stable and pre-release tags run the same graph';
    }

    if (array_keys(releaseTriggers($workflows['split.yml'] ?? [])) !== ['workflow_call']) {
        $violations[] = 'split.yml: only release.yml may call the split';
    }

    // Every qualification workflow is called on the tag.
    foreach (RELEASE_CALLED as $job => $file) {
        if (! array_key_exists('workflow_call', releaseTriggers($workflows[$file] ?? []))) {
            $violations[] = "{$file}: no workflow_call trigger";
        }

        if (($jobs[$job]['uses'] ?? null) !== './.github/workflows/'.$file) {
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

    if ($needs('split') !== $expected || ($jobs['split']['uses'] ?? null) !== './.github/workflows/split.yml') {
        $violations[] = 'release.yml: split must call split.yml and need '.implode(', ', $expected);
    }
    $expected[] = 'split';
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

    // Least privilege: write access for the release job alone.
    if (($release['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'release.yml: the workflow permissions must be contents: read';
    }
    foreach ($jobs as $id => $job) {
        $writes = array_keys(array_filter((array) ($job['permissions'] ?? []), static fn (mixed $level): bool => $level === 'write'));
        $allowed = match ($id) {
            'release' => ['contents'],
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
        || ($workflows['docs-deploy.yml']['jobs']['build']['uses'] ?? null) !== './.github/workflows/docs.yml') {
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
    'split without the compatibility gate' => [static function (array $w): array {
        $w['release.yml']['jobs']['split']['needs'] = array_values(array_diff($w['release.yml']['jobs']['split']['needs'], ['compat']));

        return $w;
    }, 'release.yml: split must call split.yml and need compat, docs, nightly, security, static, style, tests, validate, validate-consumers'],
    'release without the nightly gate' => [static function (array $w): array {
        $w['release.yml']['jobs']['release']['needs'] = array_values(array_diff($w['release.yml']['jobs']['release']['needs'], ['nightly']));

        return $w;
    }, 'release.yml: release must need compat, docs, nightly, security, split, static, style, tests, validate, validate-consumers'],
    'release without the split' => [static function (array $w): array {
        $w['release.yml']['jobs']['release']['needs'] = ['validate', 'validate-consumers', 'tests', 'static', 'style', 'docs', 'nightly', 'compat', 'security'];

        return $w;
    }, 'release.yml: release must need compat, docs, nightly, security, split, static, style, tests, validate, validate-consumers'],
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
        $w['release.yml']['on']['push']['tags'] = ['v[0-9]+.[0-9]+.[0-9]+'];

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
