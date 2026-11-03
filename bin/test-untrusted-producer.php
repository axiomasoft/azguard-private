<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/** A disabled producer never executes a staging checkout or has signing credentials. */
function producerIsBlocked(array $workflow): bool
{
    $triggers = $workflow['on'] ?? null;
    $job = $workflow['jobs']['blocked'] ?? null;
    if (! is_array($triggers) || array_keys($triggers) !== ['workflow_dispatch']
        || ($workflow['permissions'] ?? null) !== ['contents' => 'read']
        || ! is_array($workflow['jobs'] ?? null) || array_keys($workflow['jobs']) !== ['blocked']
        || ! is_array($job) || isset($job['if']) || isset($job['permissions']) || isset($job['environment'])
        || ! is_array($job['steps'] ?? null) || count($job['steps']) !== 1) {
        return false;
    }
    $step = $job['steps'][0];
    if (! is_array($step) || isset($step['if']) || isset($step['uses']) || isset($step['env'])
        || ! is_string($step['run'] ?? null)
        || preg_match("/\\Aset -Eeuo pipefail\\necho '::error::[^'\\n]+'\\nexit 1\\n?\\z/", $step['run']) !== 1) {
        return false;
    }

    return ! str_contains(json_encode($workflow, JSON_THROW_ON_ERROR), 'secrets.');
}

$root = dirname(__DIR__);
foreach (['core', 'filament'] as $package) {
    $workflow = Yaml::parseFile("$root/packages/$package/.github/workflows/provenance.yml");
    if (! is_array($workflow) || ! producerIsBlocked($workflow)) {
        throw new RuntimeException("$package producer is not safely disabled");
    }
    $unsafe = $workflow;
    $unsafe['on']['push'] = ['branches' => ['release-candidate/**']];
    if (producerIsBlocked($unsafe)) {
        throw new RuntimeException('untrusted staging push was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['env']['GH_TOKEN'] = '${{ secrets.PRIVATE_RELEASE_READ_TOKEN }}';
    if (producerIsBlocked($unsafe)) {
        throw new RuntimeException('staging secret was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['run'] = 'exit 0';
    if (producerIsBlocked($unsafe)) {
        throw new RuntimeException('successful unsigned producer was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['run'] = "set -Eeuo pipefail\nbash .github/verify-release-source.sh\nexit 1\n";
    if (producerIsBlocked($unsafe)) {
        throw new RuntimeException('execution of a staging verifier was accepted');
    }
    $command = ['bash', '-c', $workflow['jobs']['blocked']['steps'][0]['run']];
    $env = [...getenv(), 'GITHUB_REF' => 'refs/heads/release-candidate/v2.0.0/1/1'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    if (! is_resource($process) || proc_close($process) !== 1) {
        throw new RuntimeException("$package disabled producer did not fail on a staging ref");
    }
}
echo "Untrusted split producers remain blocked.\n";
