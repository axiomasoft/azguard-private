<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/package-evidence.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$workflow = Yaml::parseFile($root.'/docs/specs/trusted-producer.candidate.yml');
$job = $workflow['jobs']['inspect'] ?? [];
$steps = $job['steps'] ?? [];

if (array_keys($workflow['on'] ?? []) !== ['workflow_dispatch']
    || ($workflow['permissions'] ?? null) !== ['contents' => 'read']
    || ($job['permissions'] ?? null) !== ['contents' => 'read', 'id-token' => 'write', 'attestations' => 'write']
    || ($job['environment'] ?? null) !== 'release-producer'
    || count($steps) !== 6 || isset($steps[0]['if']) || isset($steps[2]['if'])
    || preg_match('/^exit 1$/m', $steps[0]['run'] ?? '') !== 1
    || preg_match('/^exit 1$/m', $steps[3]['run'] ?? '') !== 1) {
    throw new RuntimeException('candidate workflow is not gated and disabled');
}

if (($steps[1]['uses'] ?? null) !== 'actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803'
    || ($steps[4]['uses'] ?? null) !== 'actions/attest@7667f588f2f73a90cea6c7ac70e78266c4f76616'
    || ($steps[4]['with']['predicate-type'] ?? null) !== 'https://axiomasoft.github.io/azguard/attestations/trusted-producer/v1') {
    throw new RuntimeException('unapproved candidate actions or predicate');
}
$directory = sys_get_temp_dir().'/azguard-workflow-candidate-'.bin2hex(random_bytes(8));

if (! mkdir($directory.'/bin', 0700, true)) {
    throw new RuntimeException('cannot create fixture');
}

try {
    $marker = $directory.'/network-called';
    file_put_contents($directory.'/bin/gh', "#!/bin/sh\ntouch \"\$FIXTURE_MARKER\"\nif [ -n \"\${FIXTURE_RESPONSE:-}\" ]; then cat \"\$FIXTURE_RESPONSE\"; exit 0; fi\nexit 1\n");
    chmod($directory.'/bin/gh', 0700);
    foreach (['git', 'gpg'] as $program) {
        file_put_contents($directory.'/bin/'.$program, "#!/bin/sh\ntouch \"\$FIXTURE_MARKER-tools\"\nexit 1\n");
        chmod($directory.'/bin/'.$program, 0700);
    }
    $run = static function (string $script, array $overrides = []) use ($directory, $marker): int {
        $env = [
            'PATH' => $directory.'/bin:/usr/bin:/bin', 'RUNNER_TEMP' => $directory,
            'FIXTURE_MARKER' => $marker, 'GITHUB_REPOSITORY' => 'axiomasoft/azguard',
            'GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_REF' => 'refs/heads/main',
            'GITHUB_SHA' => str_repeat('a', 40), 'TRUSTED_WORKFLOW_SHA' => str_repeat('a', 40),
            'GH_TOKEN' => 'fixture', 'RELEASE_TAG_PUBLIC_KEY' => 'fixture',
            'RELEASE_TAG_SIGNER_FINGERPRINT' => str_repeat('A', 40),
            'CANDIDATE_SHA' => str_repeat('b', 40), 'EXPECTED_SPLIT_SHA' => str_repeat('c', 40),
            'CANDIDATE_TAG' => 'v2.0.0', 'SOURCE_RUN_ID' => '123', 'SOURCE_RUN_ATTEMPT' => '1',
        ];
        $process = proc_open(['bash', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $directory, [...$env, ...$overrides]);

        return is_resource($process) ? proc_close($process) : throw new RuntimeException('cannot run fixture');
    };

    if ($run($steps[0]['run']) !== 1 || file_exists($marker)) {
        throw new RuntimeException('the candidate guard is not fail closed');
    }
    foreach ([
        ['GITHUB_REF' => 'refs/heads/release-candidate/v2.0.0/123/1'],
        ['GITHUB_EVENT_NAME' => 'push'],
        ['GITHUB_SHA' => str_repeat('d', 40)],
        ['TRUSTED_WORKFLOW_SHA' => 'main'],
        ['CANDIDATE_SHA' => 'main'],
        ['EXPECTED_SPLIT_SHA' => 'HEAD'],
        ['CANDIDATE_TAG' => 'v2.0.0;id'],
        ['SOURCE_RUN_ID' => '0'],
        ['SOURCE_RUN_ATTEMPT' => '0'],
    ] as $bad) {
        if ($run($steps[2]['run'], $bad) === 0 || file_exists($marker)) {
            throw new RuntimeException('untrusted workflow or input reached credentialed API');
        }
    }
    $responsePath = $directory.'/run.json';
    $validRun = ['id' => 123, 'run_attempt' => 1, 'head_sha' => str_repeat('b', 40), 'head_branch' => 'v2.0.0',
        'repository' => ['full_name' => 'axiomasoft/azguard-private'], 'event' => 'push',
        'path' => '.github/workflows/release.yml', 'status' => 'in_progress', 'conclusion' => null];
    foreach ([
        ['id' => 124], ['run_attempt' => 2], ['head_sha' => str_repeat('d', 40)],
        ['head_branch' => 'v2.0.1'], ['repository' => ['full_name' => 'attacker/repo']],
        ['event' => 'pull_request'], ['path' => '.github/workflows/other.yml'],
        ['status' => 'completed', 'conclusion' => 'failure'],
    ] as $bad) {
        file_put_contents($responsePath, json_encode([...$validRun, ...$bad], JSON_THROW_ON_ERROR));

        if ($run($steps[2]['run'], ['FIXTURE_RESPONSE' => $responsePath]) === 0
            || ! file_exists($marker) || file_exists($marker.'-tools')) {
            throw new RuntimeException('untrusted source run reached git or signing');
        }
        unlink($marker);
    }
    // A valid run with absent or malformed jobs evidence must fail before Git.
    file_put_contents($responsePath, json_encode($validRun, JSON_THROW_ON_ERROR));

    if ($run($steps[2]['run'], ['FIXTURE_RESPONSE' => $responsePath]) === 0
        || ! file_exists($marker) || file_exists($marker.'-tools')) {
        throw new RuntimeException('missing qualification gate reached git or signing');
    }
    unlink($marker);

    if ($run($steps[3]['run']) !== 1 || file_exists($marker)) {
        throw new RuntimeException('candidate incorrectly allows attestation');
    }
    file_put_contents($directory.'/approved-evidence.json', '{}');
    file_put_contents($directory.'/protected-target', 'untouched');
    symlink($directory.'/protected-target', $directory.'/producer-verify.json');

    if ($run("php() { return 0; }\n".$steps[5]['run']) !== 0
        || file_get_contents($directory.'/protected-target') !== 'untouched') {
        throw new RuntimeException('workflow verifier followed predictable symlink');
    }
    foreach (glob($directory.'/trusted-verify.*') ?: [] as $temporary) {
        packageRemoveFixtureDirectory($temporary);
    }
    unlink($directory.'/producer-verify.json');
    unlink($directory.'/protected-target');
    unlink($directory.'/approved-evidence.json');
    echo "Trusted workflow candidate rejects untrusted refs and remains disabled.\n";
} finally {
    if (is_file($directory.'/run.json')) {
        unlink($directory.'/run.json');
    }
    foreach ([$directory.'/network-called', $directory.'/network-called-tools'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach (glob($directory.'/bin/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory.'/bin');
    rmdir($directory);
}
