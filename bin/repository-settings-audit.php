<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Repository settings audit (knowledge/governance#audit-script): the expected settings are the ruleset bodies in
 * `.github/rulesets/*.json` and the required contexts in `.github/rulesets/required-checks.json`; the actual settings come
 * from `gh api` (`--live`) or from recorded responses (`--fixture=<dir>`). The script only reads.
 *
 *   php bin/repository-settings-audit.php [--live] [--fixture=<dir>] [--repo=<owner/name>] [--json]
 *
 * Fixture files: rulesets.json (list), ruleset-<id>.json (detail), repo.json, pvr.json.
 * Output: one line per violation `<rule-id> <locator>: expected <x>, got <y>`. Exit 0 clean, 1 violations, 2 usage or API error.
 * Rules: S1 ruleset-missing, S2 ruleset-disabled, S3 bypass-actor, S4 rule-missing, S5 rule-parameter, S6 check-missing,
 * S7 check-unknown, S8 pvr-disabled, S9 secret-scanning-disabled, S10 push-protection-disabled.
 *
 * The functions are loadable without running the CLI (tests/Arch/RepositorySettingsAuditTest.php requires this file).
 */

final class SettingsAuditError extends RuntimeException {}

/** @param  mixed  $value */
function auditShow($value): string
{
    return is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value);
}

/**
 * @param  list<array<string, mixed>>  $actors
 * @return list<string>
 */
function auditActors(array $actors): array
{
    $normal = array_map(static fn (array $actor): string => ($actor['actor_type'] ?? '').':'.($actor['actor_id'] ?? '').':'.($actor['bypass_mode'] ?? ''), $actors);
    sort($normal);

    return $normal;
}

/**
 * @param  array<string, array<string, mixed>>  $expected  ruleset name => body
 * @param  list<array<string, mixed>>  $listing  `GET /rulesets`
 * @param  array<int|string, array<string, mixed>>  $details  id => `GET /rulesets/{id}`
 * @param  list<string>  $jobNames  literal job names of the workflows
 * @param  list<string>  $contexts  required-checks.json
 * @param  array<string, mixed>  $repo
 * @param  array<string, mixed>  $pvr
 * @return list<string>
 */
function settingsAuditViolations(array $expected, array $listing, array $details, array $jobNames, array $contexts, array $repo, array $pvr): array
{
    $violations = [];
    $byName = [];

    foreach ($listing as $row) {
        $byName[$row['name'] ?? ''] = $row;
    }

    foreach ($expected as $name => $want) {
        $row = $byName[$name] ?? null;

        if ($row === null) {
            $violations[] = "S1 ruleset {$name}: expected present, got absent";

            continue;
        }

        $have = $details[$row['id'] ?? ''] ?? $row;

        if (($have['enforcement'] ?? null) !== 'active') {
            $violations[] = "S2 ruleset {$name}: expected enforcement 'active', got ".auditShow($have['enforcement'] ?? null);
        }

        if (auditActors($have['bypass_actors'] ?? []) !== auditActors($want['bypass_actors'] ?? [])) {
            $violations[] = "S3 ruleset {$name}: expected bypass actors ".auditShow(auditActors($want['bypass_actors'] ?? [])).', got '.auditShow(auditActors($have['bypass_actors'] ?? []));
        }

        $haveRules = [];

        foreach ($have['rules'] ?? [] as $rule) {
            $haveRules[$rule['type'] ?? ''] = $rule;
        }

        foreach ($want['rules'] ?? [] as $rule) {
            $type = $rule['type'];

            if (! isset($haveRules[$type])) {
                $violations[] = "S4 ruleset {$name} rule {$type}: expected present, got absent";

                continue;
            }

            foreach ($rule['parameters'] ?? [] as $key => $value) {
                if ($key === 'required_status_checks') {
                    $haveContexts = array_map(static fn (array $check): string => $check['context'] ?? '', $haveRules[$type]['parameters'][$key] ?? []);

                    foreach (array_diff(array_map(static fn (array $check): string => $check['context'], $value), $haveContexts) as $missing) {
                        $violations[] = "S6 ruleset {$name} check {$missing}: expected required, got absent";
                    }

                    continue;
                }

                if (($haveRules[$type]['parameters'][$key] ?? null) !== $value) {
                    $violations[] = "S5 ruleset {$name} rule {$type} {$key}: expected ".auditShow($value).', got '.auditShow($haveRules[$type]['parameters'][$key] ?? null);
                }
            }
        }
    }

    $mainContexts = array_map(static fn (array $check): string => $check['context'], array_values(array_filter($expected['main']['rules'] ?? [], static fn (array $rule): bool => $rule['type'] === 'required_status_checks'))[0]['parameters']['required_status_checks'] ?? []);

    if ($mainContexts !== $contexts) {
        $violations[] = 'S5 ruleset main required_status_checks: expected the contexts of required-checks.json, got '.auditShow($mainContexts);
    }

    foreach (array_diff($contexts, $jobNames) as $unknown) {
        $violations[] = "S7 check {$unknown}: expected a workflow job with this name, got none";
    }

    if (($pvr['enabled'] ?? false) !== true) {
        $violations[] = 'S8 private vulnerability reporting: expected enabled, got '.auditShow($pvr['enabled'] ?? null);
    }

    $analysis = $repo['security_and_analysis'] ?? [];

    if (($analysis['secret_scanning']['status'] ?? null) !== 'enabled') {
        $violations[] = 'S9 secret scanning: expected enabled, got '.auditShow($analysis['secret_scanning']['status'] ?? null);
    }

    if (($analysis['secret_scanning_push_protection']['status'] ?? null) !== 'enabled') {
        $violations[] = 'S10 push protection: expected enabled, got '.auditShow($analysis['secret_scanning_push_protection']['status'] ?? null);
    }

    return $violations;
}

/** @return list<string> literal job names of the workflows of a directory (matrix names are skipped) */
function auditWorkflowJobNames(string $directory): array
{
    $names = [];

    foreach (glob($directory.'/*.yml') ?: [] as $file) {
        foreach (Yaml::parseFile($file)['jobs'] ?? [] as $id => $job) {
            $name = (string) ($job['name'] ?? $id);

            if (! str_contains($name, '${{')) {
                $names[] = $name;
            }
        }
    }

    return array_values(array_unique($names));
}

/** @return array<string, mixed> */
function auditReadJson(string $path): array
{
    $data = json_decode((string) @file_get_contents($path), true);

    if (! is_array($data)) {
        throw new SettingsAuditError("unreadable JSON: {$path}");
    }

    return $data;
}

/** @return array<mixed> */
function auditGh(string $endpoint): array
{
    $process = proc_open(['gh', 'api', $endpoint], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new SettingsAuditError('cannot start gh');
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new SettingsAuditError("gh api {$endpoint}: ".trim($err));
    }

    $data = json_decode($out, true);

    if (! is_array($data)) {
        throw new SettingsAuditError("gh api {$endpoint}: not JSON");
    }

    return $data;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $options = ['repo' => 'axiomasoft/azguard-private'];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--live' || $argument === '--json') {
            $options[substr($argument, 2)] = true;
        } elseif (preg_match('/^--(fixture|repo)=(.+)$/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } else {
            fwrite(STDERR, "repository-settings-audit: unknown argument {$argument}\n");
            exit(2);
        }
    }

    if (isset($options['live']) === isset($options['fixture'])) {
        fwrite(STDERR, "repository-settings-audit: pass exactly one of --live and --fixture=<dir>\n");
        exit(2);
    }

    require_once $root.'/vendor/autoload.php';

    try {
        $expected = [];

        foreach (glob($root.'/.github/rulesets/*.json') ?: [] as $file) {
            if (basename($file) !== 'required-checks.json') {
                $body = auditReadJson($file);
                $expected[$body['name']] = $body;
            }
        }

        $contexts = auditReadJson($root.'/.github/rulesets/required-checks.json')['contexts'];

        if (isset($options['live'])) {
            $repository = $options['repo'];
            $listing = auditGh("repos/{$repository}/rulesets");
            $details = [];

            foreach ($listing as $row) {
                $details[$row['id']] = auditGh("repos/{$repository}/rulesets/{$row['id']}");
            }

            $repo = auditGh("repos/{$repository}");
            $pvr = auditGh("repos/{$repository}/private-vulnerability-reporting");
        } else {
            $directory = rtrim($options['fixture'], '/');
            $listing = auditReadJson($directory.'/rulesets.json');
            $details = [];

            foreach ($listing as $row) {
                $details[$row['id']] = auditReadJson($directory.'/ruleset-'.$row['id'].'.json');
            }

            $repo = auditReadJson($directory.'/repo.json');
            $pvr = auditReadJson($directory.'/pvr.json');
        }
    } catch (SettingsAuditError $error) {
        fwrite(STDERR, 'repository-settings-audit: '.$error->getMessage()."\n");
        exit(2);
    }

    $violations = settingsAuditViolations($expected, $listing, $details, auditWorkflowJobNames($root.'/.github/workflows'), $contexts, $repo, $pvr);

    if (isset($options['json'])) {
        echo json_encode($violations, JSON_PRETTY_PRINT)."\n";
    } else {
        foreach ($violations as $violation) {
            echo $violation."\n";
        }

        if ($violations === []) {
            echo "repository-settings-audit: clean\n";
        }
    }

    exit($violations === [] ? 0 : 1);
}
