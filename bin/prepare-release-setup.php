<?php

declare(strict_types=1);

require_once __DIR__.'/verify-release-setup.php';

$root = dirname(__DIR__);
$observations = [
    'observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'repositories' => [],
    'authenticated_settings_audit' => 'unavailable',
    'release_environment' => 'unavailable',
    'attestation_entitlements' => 'unverified; fresh signed setup probes are required',
];
foreach (array_keys(releaseSetupPaths()) as $repo) {
    [$exit, $output] = releaseSetupCommand(['gh', 'api', 'repos/'.$repo]);
    $method = 'authenticated gh';
    if ($exit !== 0) {
        [$exit, $output] = releaseSetupCommand(['curl', '--silent', '--show-error', '--fail', '--max-time', '15', 'https://api.github.com/repos/'.$repo]);
        $method = 'anonymous API';
    }
    $data = $exit === 0 ? json_decode($output, true) : null;
    if (is_array($data) && ($data['full_name'] ?? null) === $repo && is_bool($data['private'] ?? null)
        && is_string($data['default_branch'] ?? null) && is_bool($data['archived'] ?? null)) {
        $observations['repositories'][$repo] = ['status' => 'observed',
            'detail' => $method.'; visibility='.($data['private'] ? 'private' : 'public').'; default_branch='.$data['default_branch'].'; archived='.($data['archived'] ? 'true' : 'false')];
    } else {
        $observations['repositories'][$repo] = ['status' => 'unavailable', 'detail' => 'API unavailable or repository inaccessible; absence is not established'];
    }
}
[$auditExit] = releaseSetupCommand([PHP_BINARY, $root.'/bin/repository-settings-audit.php', '--live', '--json']);
$observations['authenticated_settings_audit'] = $auditExit === 0 ? 'passed at observation time' : 'unavailable or failed (exit '.$auditExit.')';
[$environmentExit, $environmentOutput] = releaseSetupCommand(['gh', 'api', 'repos/axiomasoft/azguard-private/environments/release']);
$environment = $environmentExit === 0 ? json_decode($environmentOutput, true) : null;
if (is_array($environment) && ($environment['name'] ?? null) === 'release' && is_array($environment['protection_rules'] ?? null)) {
    $count = 0;
    foreach ($environment['protection_rules'] as $rule) {
        if (is_array($rule) && ($rule['type'] ?? null) === 'required_reviewers' && is_array($rule['reviewers'] ?? null)) {
            $count += count($rule['reviewers']);
        }
    }
    $observations['release_environment'] = 'observed; required reviewer count='.$count;
}
$request = [
    'schema' => 'azguard.release.setup-request.v1',
    'candidate_sha' => null,
    'remote_changes_authorized' => false,
    'status' => 'reviewable proposal; applied settings and entitlements are not proven',
    'observations' => $observations,
    'proposal' => releaseSetupProposal($root),
];
$directory = $root.'/build/release';
if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    throw new RuntimeException('cannot create setup request directory');
}
$path = $directory.'/setup-request.json';
$temporary = tempnam($directory, '.setup-request-');
$encoded = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || ! rename($temporary, $path)) {
    throw new RuntimeException('cannot write setup request');
}
echo "build/release/setup-request.json (proposal only; no remote changes)\n";
