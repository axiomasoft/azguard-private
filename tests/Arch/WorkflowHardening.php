<?php

declare(strict_types=1);

/*
 * Workflow hardening gate, rules W1-W10 (knowledge/supply-chain#gate). A pure checker: it receives the file name, the raw
 * text and the parsed YAML of one workflow and returns one `<rule-id> <file>:<locator>` string per violation. W1 reads the
 * raw text (a version comment is not in the parsed YAML), W9 reads both.
 */

/** W2: the only step allowed to continue on error is an upload, not a check. Workflow => job => step names. */
const WORKFLOW_CONTINUE_ON_ERROR = ['tests.yml' => ['coverage' => ['Upload coverage to Codecov']]];

/** W6: job-level write permissions, knowledge/supply-chain#permissions. Workflow => job => permission keys that may be `write`. */
const WORKFLOW_WRITE_PERMISSIONS = [
    'release.yml' => ['release' => ['contents'], 'artifacts' => ['id-token', 'attestations']],
    'docs-deploy.yml' => ['deploy' => ['pages', 'id-token']],
    'codeql.yml' => ['analyze' => ['security-events']],
    'dependabot-auto-merge.yml' => ['auto-merge' => ['contents', 'pull-requests']],
    'pr-check.yml' => ['size' => ['pull-requests']],
];

/** The longest an allowlist entry may live, in days from the day it is checked. */
const ZIZMOR_ALLOWLIST_MAX_DAYS = 180;

/**
 * @param  array<mixed>  $parsed
 * @return list<string>
 */
function workflowHardeningViolations(string $file, string $raw, array $parsed): array
{
    $name = basename($file);
    $violations = [];
    $jobs = is_array($parsed['jobs'] ?? null) ? $parsed['jobs'] : [];

    foreach (explode("\n", $raw) as $index => $line) {
        if (preg_match('/^\s*(?:-\s*)?uses:\s*(\S+)(.*)$/', $line, $match) !== 1 || str_starts_with($match[1], './')) {
            continue;
        }

        $pinned = preg_match('/@[0-9a-f]{40}$/', $match[1]) === 1;
        $commented = preg_match('/^\s+#\s*v?\d/', $match[2]) === 1;

        if (! $pinned || ! $commented) {
            $violations[] = 'unpinned-action '.$name.':'.($index + 1);
        }
    }

    $permissions = $parsed['permissions'] ?? null;

    if (! workflowReadOnlyPermissions($permissions)) {
        $violations[] = 'top-level-permissions '.$name.':permissions';
    }

    if (($parsed['defaults']['run']['shell'] ?? null) !== 'bash') {
        $violations[] = 'strict-shell '.$name.':defaults.run.shell';
    }

    foreach (workflowTriggerNames($parsed) as $trigger) {
        if (in_array($trigger, ['pull_request_target', 'workflow_run'], true)) {
            $violations[] = 'dangerous-trigger '.$name.':on.'.$trigger;
        }
    }

    foreach (workflowSecretsInherit($parsed, '') as $locator) {
        $violations[] = 'secrets-inherit '.$name.':'.$locator;
    }

    foreach ($jobs as $jobId => $job) {
        if (! is_array($job)) {
            continue;
        }

        $jobLocator = $name.':jobs.'.$jobId;

        if (workflowTruthy($job['continue-on-error'] ?? false)) {
            $violations[] = 'continue-on-error '.$jobLocator;
        }

        foreach (workflowWriteViolations($name, (string) $jobId, $job['permissions'] ?? null) as $permission) {
            $violations[] = 'write-permission '.$jobLocator.'.permissions.'.$permission;
        }

        foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $locator = $jobLocator.'.steps['.$index.']';
            $stepName = (string) ($step['name'] ?? '');

            if (workflowTruthy($step['continue-on-error'] ?? false) && ! in_array($stepName, WORKFLOW_CONTINUE_ON_ERROR[$name][$jobId] ?? [], true)) {
                $violations[] = 'continue-on-error '.$locator;
            }

            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@') && ($step['with']['persist-credentials'] ?? null) !== false) {
                $violations[] = 'persisted-credentials '.$locator;
            }

            if (! is_string($step['run'] ?? null)) {
                continue;
            }

            foreach (workflowRunViolations($step['run']) as $rule) {
                $violations[] = $rule.' '.$locator;
            }
        }
    }

    return $violations;
}

/**
 * Every ignore entry of the zizmor allowlist carries `# owner: @<login>; reason: <text>; expires: YYYY-MM-DD` on the line
 * above it, expires in the future and at most {@see ZIZMOR_ALLOWLIST_MAX_DAYS} days ahead, names a line, and no rule is disabled.
 *
 * @return list<string>
 */
function zizmorAllowlistViolations(string $raw, DateTimeImmutable $today): array
{
    $violations = [];
    $lines = explode("\n", $raw);
    $limit = $today->modify('+'.ZIZMOR_ALLOWLIST_MAX_DAYS.' days');

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*ignore:\s*\[\s*[^\]\s]/', $line) === 1) {
            $violations[] = 'allowlist-flow-list line '.($index + 1);
        }

        if (preg_match('/^\s*disable:\s*true\b/', $line) === 1) {
            $violations[] = 'allowlist-disabled-rule line '.($index + 1);
        }

        if (preg_match('/^\s*-\s*["\']?([^"\'\s#]+)/', $line, $entry) !== 1) {
            continue;
        }

        $locator = 'line '.($index + 1);

        if (preg_match('/^[^:]+:\d+(:\d+)?$/', $entry[1]) !== 1) {
            $violations[] = 'allowlist-wholesale '.$locator;
        }

        $comment = $lines[$index - 1] ?? '';

        if (preg_match('/^\s*#\s*owner:\s*@[\w-]+;\s*reason:\s*\S.*;\s*expires:\s*(\d{4}-\d{2}-\d{2})\s*$/', $comment, $match) !== 1) {
            $violations[] = 'allowlist-unannotated '.$locator;

            continue;
        }

        $expires = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);

        if ($expires === false || $expires <= $today) {
            $violations[] = 'allowlist-expired '.$locator;
        } elseif ($expires > $limit) {
            $violations[] = 'allowlist-too-long '.$locator;
        }
    }

    return $violations;
}

/** W3, W9 and W10 on one `run:` string. @return list<string> */
function workflowRunViolations(string $run): array
{
    $rules = [];

    foreach (explode("\n", $run) as $line) {
        if (preg_match('/(\|\|\s*(true|:)|;\s*true)\s*$/', $line) === 1) {
            $rules[] = 'masked-failure';
        }
    }

    if (preg_match('/\$\{\{\s*(github\.event\.|github\.head_ref|inputs\.|github\.ref_name)/', $run) === 1) {
        $rules[] = 'untrusted-interpolation';
    }

    $trimmed = trim($run);

    if (str_contains($trimmed, "\n")) {
        foreach (explode("\n", $trimmed) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (trim($line) !== 'set -Eeuo pipefail') {
                $rules[] = 'strict-shell';
            }

            break;
        }
    }

    return array_values(array_unique($rules));
}

/** W5: a missing value, or anything but `read`/`none`/`{}` per scope. */
function workflowReadOnlyPermissions(mixed $permissions): bool
{
    if ($permissions === 'read-all' || $permissions === []) {
        return true;
    }

    if (! is_array($permissions)) {
        return false;
    }

    foreach ($permissions as $level) {
        if (! in_array($level, ['read', 'none'], true)) {
            return false;
        }
    }

    return true;
}

/** W6: the `write` scopes of a job-level permissions value that the allowlist does not cover. @return list<string> */
function workflowWriteViolations(string $file, string $jobId, mixed $permissions): array
{
    if ($permissions === 'write-all') {
        return ['write-all'];
    }

    if (! is_array($permissions)) {
        return [];
    }

    $allowed = WORKFLOW_WRITE_PERMISSIONS[$file][$jobId] ?? [];

    return array_values(array_filter(
        array_map('strval', array_keys($permissions, 'write', true)),
        static fn (string $scope): bool => ! in_array($scope, $allowed, true),
    ));
}

/** @param  array<mixed>  $parsed @return list<string> the trigger names; YAML 1.1 readers turn the key `on` into `true` */
function workflowTriggerNames(array $parsed): array
{
    $on = $parsed['on'] ?? $parsed[true] ?? [];

    if (is_string($on)) {
        return [$on];
    }

    if (! is_array($on)) {
        return [];
    }

    return array_values(array_map('strval', array_is_list($on) ? $on : array_keys($on)));
}

/** W8: the locators of every `secrets: inherit` in the tree. @return list<string> */
function workflowSecretsInherit(mixed $node, string $path): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];

    foreach ($node as $key => $value) {
        $locator = $path === '' ? (string) $key : $path.'.'.$key;

        if ($key === 'secrets' && $value === 'inherit') {
            $found[] = $locator;
        }

        $found = [...$found, ...workflowSecretsInherit($value, $locator)];
    }

    return $found;
}

/** W2: `true`, the string `true` and any expression count; only `false` and an absent key do not. */
function workflowTruthy(mixed $value): bool
{
    return $value !== false && $value !== null && $value !== 'false';
}
