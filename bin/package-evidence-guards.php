<?php

declare(strict_types=1);

/** Static validation only: this fixture never executes a workflow step. */
function splitPublicationGuardIsFailClosed(array $workflow): bool
{
    $job = $workflow['jobs']['split'] ?? null;
    $guard = is_array($job) ? ($job['steps'][0] ?? null) : null;

    if (! is_array($guard) || array_keys($guard) !== ['name', 'run']
        || ($guard['name'] ?? null) !== 'Block split publication'
        || ! is_string($guard['run'] ?? null)
        || trim($guard['run']) !== "set -Eeuo pipefail\necho '::error::Split publication is disabled by the project owner.'\nexit 1") {
        return false;
    }

    // Job-level skip/conditional must not turn an actual run into a false success.
    return ! isset($job['if']) && ! isset($job['continue-on-error']);
}

function disabledProducer(array $workflow): bool
{
    // Exact shape prevents an added job, trigger, environment, expression or step
    // from reading a secret or doing work before the unconditional failure.
    $serialized = json_encode($workflow, JSON_THROW_ON_ERROR);

    if (str_contains($serialized, '${{') || str_contains(strtolower($serialized), 'secrets.')
        || array_diff(array_keys($workflow), ['name', 'on', 'permissions', 'defaults', 'jobs']) !== []
        || array_keys($workflow['on'] ?? []) !== ['workflow_dispatch']
        || $workflow['on']['workflow_dispatch'] !== null
        || ($workflow['permissions'] ?? null) !== ['contents' => 'read']
        || ($workflow['defaults'] ?? null) !== ['run' => ['shell' => 'bash']]
        || array_keys($workflow['jobs'] ?? []) !== ['blocked']) {
        return false;
    }
    $job = $workflow['jobs']['blocked'];

    if (! is_array($job) || array_diff(array_keys($job), ['name', 'runs-on', 'steps']) !== []
        || ($job['runs-on'] ?? null) !== 'ubuntu-latest' || ! is_array($job['steps'] ?? null)
        || count($job['steps']) !== 1 || ! is_array($job['steps'][0] ?? null)) {
        return false;
    }
    $step = $job['steps'][0];

    return array_diff(array_keys($step), ['name', 'run']) === []
        && is_string($step['name'] ?? null)
        && is_string($step['run'] ?? null)
        && preg_match("~\\Aset -Eeuo pipefail\\necho '[^'\\n]*'\\nexit 1\\n?\\z~", $step['run']) === 1;
}

function guardsHold(array $split, array $core, array $filament): bool
{
    $job = $split['jobs']['split'] ?? [];
    $steps = $job['steps'] ?? [];

    if (! is_array($steps) || ! splitPublicationGuardIsFailClosed($split)) {
        return false;
    }
    $setupIndexes = [];
    $splitIndexes = [];
    foreach ($steps as $index => $step) {
        if (! is_array($step)) {
            return false;
        }

        if (($step['name'] ?? null) === 'Require verified release setup') {
            $setupIndexes[] = $index;
        }

        if (str_contains((string) ($step['run'] ?? ''), 'git subtree split')
            || str_contains((string) ($step['run'] ?? ''), 'git push')) {
            $splitIndexes[] = $index;
        }
    }

    if (count($setupIndexes) !== 1 || $setupIndexes[0] <= 0 || $splitIndexes === []
        || min($splitIndexes) <= $setupIndexes[0]) {
        return false;
    }
    $guard = $steps[$setupIndexes[0]];
    $script = $guard['run'] ?? '';

    return ($job['strategy']['matrix']['package'] ?? null) === [['dir' => 'core', 'name' => 'azguard']]
        && ! isset($guard['if']) && ! isset($guard['continue-on-error'])
        && is_string($script)
        && str_contains($script, 'php bin/prepare-release-setup.php')
        && str_contains($script, 'php bin/verify-release-evidence.php --setup build/release/setup-request.json')
        && disabledProducer($core) && disabledProducer($filament);
}
