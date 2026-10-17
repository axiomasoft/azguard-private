<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Release evidence validator (knowledge/release-integrity#evidence): one file `release-evidence/v<version>.yml` binds a
 * release commit to the workflow runs, local runs and artifacts that qualified it. The validator only reads.
 *
 *   php bin/release-evidence.php <file> [--live] [--fixture-runs=<dir>] [--require-approval] [--repo=axiomasoft/azguard-private]
 *
 * Offline: the schema id, SemVer, 40-hex commit and candidate, workflow URLs of the repository, `local` rows with exit 0,
 * 64-hex artifact digests, split trees identical. `--live` adds the cross-checks against GitHub (`gh api`, argv arrays):
 * every run is `completed` with conclusion `success` on the evidence commit, the tag `v<version>` dereferences to the commit,
 * the release run is the `push` of that tag, and each artifact digest has an attestation. `--fixture-runs=<dir>` replaces
 * the gh calls with recorded responses: `<dir>/<run id>.json` (head_sha, conclusion, status, event, head_branch),
 * `<dir>/tag.json` ({"sha": ...}) and `<dir>/attestations.json` (list of digests).
 * `--require-approval` demands `owner_approval`.
 *
 * Output: one line per violation `<rule-id> <field>: <reason>`. Exit 0 clean, 1 violations, 2 usage or read error.
 * Rules: E1 schema, E2 version, E3 commit, E4 created_at, E5 workflows, E6 local, E7 artifacts, E8 split, E9 required
 * field; L1 run commit, L2 run conclusion, L3 tag target, L4 release run event, L5 attestation; A1 approval.
 *
 * The functions are loadable without running the CLI (tests/Arch/ReleaseEvidenceTest.php requires this file).
 */

const EVIDENCE_SCHEMA = 'urn:azguard:release-evidence:1';

const EVIDENCE_WORKFLOWS = ['release', 'tests', 'nightly', 'compatibility', 'static', 'style', 'docs', 'security', 'zizmor'];

const EVIDENCE_ARTIFACTS = ['core_archive', 'filament_archive', 'sbom_core', 'sbom_filament'];

const EVIDENCE_SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/';

final class EvidenceError extends RuntimeException {}

/**
 * Offline violations of an evidence document.
 *
 * @param  array<mixed>  $evidence
 * @return list<string>
 */
function evidenceViolations(array $evidence, string $repo = 'axiomasoft/azguard-private'): array
{
    $violations = [];
    $show = static fn (mixed $value): string => is_scalar($value) ? (string) $value : get_debug_type($value);

    if (($evidence['schema'] ?? null) !== EVIDENCE_SCHEMA) {
        $violations[] = 'E1 schema: expected '.EVIDENCE_SCHEMA.', got '.$show($evidence['schema'] ?? null);
    }

    if (! is_string($evidence['version'] ?? null) || preg_match(EVIDENCE_SEMVER, $evidence['version']) !== 1) {
        $violations[] = 'E2 version: expected SemVer, got '.$show($evidence['version'] ?? null);
    }

    foreach (['commit', 'candidate'] as $field) {
        if (! is_string($evidence[$field] ?? null) || preg_match('/^[0-9a-f]{40}$/', $evidence[$field]) !== 1) {
            $violations[] = "E3 {$field}: expected 40 hexadecimal characters, got ".$show($evidence[$field] ?? null);
        }
    }

    $created = $evidence['created_at'] ?? null;
    $created = $created instanceof DateTimeInterface ? $created->format('Y-m-d\TH:i:s\Z') : $created;

    if (! is_string($created) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $created) !== 1) {
        $violations[] = 'E4 created_at: expected a UTC ISO-8601 time, got '.$show($created);
    }

    $workflows = is_array($evidence['workflows'] ?? null) ? $evidence['workflows'] : [];

    foreach (EVIDENCE_WORKFLOWS as $name) {
        $url = $workflows[$name] ?? null;

        if (! is_string($url) || preg_match('#^https://github\.com/'.preg_quote($repo, '#').'/actions/runs/\d+$#', $url) !== 1) {
            $violations[] = "E5 workflows.{$name}: expected a run URL of {$repo}, got ".$show($url);
        }
    }

    $local = $evidence['local'] ?? null;

    if (! is_array($local) || $local === []) {
        $violations[] = 'E6 local: expected at least one row';
    } else {
        foreach ($local as $index => $row) {
            if (! is_array($row) || ($row['exit'] ?? null) !== 0 || ! is_string($row['command'] ?? null) || $row['command'] === '') {
                $violations[] = "E6 local.{$index}: expected a command with exit 0, got ".$show(is_array($row) ? ($row['exit'] ?? null) : $row);
            }
        }
    }

    foreach (EVIDENCE_ARTIFACTS as $name) {
        $digest = $evidence['artifacts'][$name] ?? null;

        if (! is_string($digest) || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            $violations[] = "E7 artifacts.{$name}: expected 64 hexadecimal characters, got ".$show($digest);
        }
    }

    foreach (['core', 'filament'] as $name) {
        $split = $evidence['split'][$name] ?? null;

        if (! is_array($split) || ! is_string($split['tag_sha'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $split['tag_sha']) !== 1) {
            $violations[] = "E8 split.{$name}.tag_sha: expected 40 hexadecimal characters";
        }

        if (! is_array($split) || ($split['tree_identical'] ?? null) !== true) {
            $violations[] = "E8 split.{$name}.tree_identical: expected true, got ".$show(is_array($split) ? ($split['tree_identical'] ?? null) : null);
        }
    }

    foreach (['toolchain', 'packagist', 'benchmarks', 'settings_audit'] as $field) {
        if (! isset($evidence[$field]) || $evidence[$field] === '' || $evidence[$field] === []) {
            $violations[] = "E9 {$field}: expected a value";
        }
    }

    return $violations;
}

/**
 * The cross-checks against recorded or live GitHub state.
 *
 * @param  array<mixed>  $evidence
 * @param  callable(string): ?array<mixed>  $run  run id => its JSON, null when unreadable
 * @param  callable(): ?string  $tagTarget  the commit the tag `v<version>` dereferences to
 * @param  callable(string): bool  $attested  digest => an attestation exists
 * @return list<string>
 */
function evidenceLiveViolations(array $evidence, callable $run, callable $tagTarget, callable $attested): array
{
    $violations = [];
    $commit = (string) ($evidence['commit'] ?? '');

    foreach (EVIDENCE_WORKFLOWS as $name) {
        $url = (string) ($evidence['workflows'][$name] ?? '');

        if (preg_match('#/runs/(\d+)$#', $url, $match) !== 1) {
            continue;
        }
        $data = $run($match[1]);

        if ($data === null) {
            $violations[] = "L1 workflows.{$name}: run {$match[1]} is unreadable";

            continue;
        }

        if (($data['head_sha'] ?? null) !== $commit) {
            $violations[] = "L1 workflows.{$name}: expected head_sha {$commit}, got ".($data['head_sha'] ?? 'none');
        }

        if (($data['status'] ?? 'completed') !== 'completed' || ($data['conclusion'] ?? null) !== 'success') {
            $violations[] = "L2 workflows.{$name}: expected success, got ".($data['conclusion'] ?? 'none');
        }

        if ($name === 'release' && (($data['event'] ?? null) !== 'push' || ($data['head_branch'] ?? null) !== 'v'.($evidence['version'] ?? ''))) {
            $violations[] = 'L4 workflows.release: expected the push of tag v'.($evidence['version'] ?? '').', got '.($data['event'] ?? 'none').' on '.($data['head_branch'] ?? 'none');
        }
    }

    $target = $tagTarget();

    if ($target !== $commit) {
        $violations[] = 'L3 tag: expected v'.($evidence['version'] ?? '').' to point to '.$commit.', got '.($target ?? 'none');
    }

    foreach (EVIDENCE_ARTIFACTS as $name) {
        $digest = (string) ($evidence['artifacts'][$name] ?? '');

        if ($digest !== '' && ! $attested($digest)) {
            $violations[] = "L5 artifacts.{$name}: expected an attestation for sha256:{$digest}, got none";
        }
    }

    return $violations;
}

/** @return list<string> */
function evidenceApprovalViolations(array $evidence): array
{
    $approval = $evidence['owner_approval'] ?? null;

    return is_array($approval) && ($approval['actor'] ?? '') !== '' && ($approval['timestamp'] ?? '') !== ''
        ? []
        : ['A1 owner_approval: expected actor and timestamp, got none'];
}

/** @return array<mixed>|null */
function evidenceGh(string $endpoint): ?array
{
    $process = proc_open(['gh', 'api', $endpoint], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new EvidenceError('cannot start gh');
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    if (proc_close($process) !== 0) {
        return null;
    }
    $data = json_decode($out, true);

    return is_array($data) ? $data : null;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = ['repo' => 'axiomasoft/azguard-private'];
    $file = null;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--live' || $argument === '--require-approval') {
            $options[substr($argument, 2)] = true;
        } elseif (preg_match('/^--(repo|fixture-runs)=(.+)$/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } elseif ($file === null && ! str_starts_with($argument, '--')) {
            $file = $argument;
        } else {
            fwrite(STDERR, "release-evidence: unknown argument {$argument}\n");
            exit(2);
        }
    }

    if ($file === null) {
        fwrite(STDERR, "usage: release-evidence.php <file> [--live] [--fixture-runs=<dir>] [--require-approval] [--repo=<owner/name>]\n");
        exit(2);
    }

    require_once dirname(__DIR__).'/vendor/autoload.php';

    try {
        $evidence = Yaml::parseFile($file, Yaml::PARSE_DATETIME);
    } catch (Throwable $error) {
        fwrite(STDERR, 'release-evidence: cannot read '.$file.': '.$error->getMessage()."\n");
        exit(2);
    }

    if (! is_array($evidence)) {
        fwrite(STDERR, "release-evidence: {$file} is not a mapping\n");
        exit(2);
    }

    $violations = evidenceViolations($evidence, $options['repo']);

    if (isset($options['live']) || isset($options['fixture-runs'])) {
        $repo = $options['repo'];
        $dir = isset($options['fixture-runs']) ? rtrim($options['fixture-runs'], '/') : null;
        $tag = 'v'.($evidence['version'] ?? '');

        $run = static function (string $id) use ($dir, $repo): ?array {
            if ($dir !== null) {
                $data = json_decode((string) @file_get_contents($dir.'/'.$id.'.json'), true);

                return is_array($data) ? $data : null;
            }

            return evidenceGh("repos/{$repo}/actions/runs/{$id}");
        };
        $tagTarget = static function () use ($dir, $repo, $tag): ?string {
            if ($dir !== null) {
                $data = json_decode((string) @file_get_contents($dir.'/tag.json'), true);

                return is_array($data) ? ($data['sha'] ?? null) : null;
            }
            $ref = evidenceGh("repos/{$repo}/git/ref/tags/{$tag}");
            $object = $ref['object'] ?? null;

            if (($object['type'] ?? null) === 'tag') {
                $object = evidenceGh("repos/{$repo}/git/tags/".$object['sha'])['object'] ?? null;
            }

            return $object['sha'] ?? null;
        };
        $attested = static function (string $digest) use ($dir, $repo): bool {
            if ($dir !== null) {
                $list = json_decode((string) @file_get_contents($dir.'/attestations.json'), true);

                return is_array($list) && in_array($digest, $list, true);
            }
            $data = evidenceGh("repos/{$repo}/attestations/sha256:{$digest}");

            return is_array($data) && ($data['attestations'] ?? []) !== [];
        };
        array_push($violations, ...evidenceLiveViolations($evidence, $run, $tagTarget, $attested));
    }

    if (isset($options['require-approval'])) {
        array_push($violations, ...evidenceApprovalViolations($evidence));
    }

    foreach ($violations as $violation) {
        echo $violation."\n";
    }

    if ($violations === []) {
        echo "release-evidence: clean\n";
    }

    exit($violations === [] ? 0 : 1);
}
