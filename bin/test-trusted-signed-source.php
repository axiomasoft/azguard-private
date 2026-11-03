<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-core-producer-candidate.php';

$root = sys_get_temp_dir().'/azguard-signed-producer-'.bin2hex(random_bytes(8));
if (! mkdir($root, 0700) || ! mkdir($root.'/keys', 0700) || ! mkdir($root.'/repo/packages/core', 0700, true)) {
    throw new RuntimeException('cannot create signed source fixture');
}
$original = [];
foreach (['GNUPGHOME', 'RELEASE_TAG_PUBLIC_KEY', 'RELEASE_TAG_SIGNER_FINGERPRINT'] as $name) {
    $original[$name] = getenv($name);
}
putenv('GNUPGHOME='.$root.'/keys');
try {
    packageEvidenceCommand(['gpg', '--batch', '--pinentry-mode', 'loopback', '--passphrase', '',
        '--quick-generate-key', 'Fixture <fixture@example.invalid>', 'ed25519', 'sign', '0']);
    $listing = packageEvidenceCommand(['gpg', '--batch', '--with-colons', '--fingerprint']);
    if (preg_match('/^fpr:::::::::([0-9A-F]{40}):/m', $listing, $match) !== 1) {
        throw new RuntimeException('fixture fingerprint missing');
    }
    $fingerprint = $match[1];
    $public = packageEvidenceCommand(['gpg', '--batch', '--armor', '--export', $fingerprint]);
    putenv('RELEASE_TAG_PUBLIC_KEY='.$public);
    putenv('RELEASE_TAG_SIGNER_FINGERPRINT='.$fingerprint);
    $repo = $root.'/repo';
    $git = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $repo, ...$args]));
    $git('init', '-q');
    file_put_contents($repo.'/packages/core/composer.json', '{"name":"axiomasoft/azguard"}');
    $git('add', 'packages');
    $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture');
    $source = $git('rev-parse', 'HEAD');
    $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'user.signingkey='.$fingerprint,
        'tag', '-s', 'v2.0.0', '-m', 'fixture');
    $split = $git('subtree', 'split', '--prefix=packages/core', $source);
    $approved = ['workflow_sha' => str_repeat('a', 40), 'source_sha' => $source,
        'split_sha' => $split, 'tag' => 'v2.0.0'];
    packageVerifySignedTag($repo, 'v2.0.0', $source);
    $git('gc', '--prune=now');
    $packIndex = glob($repo.'/.git/objects/pack/*.idx') ?: [];
    if (count($packIndex) !== 1) {
        throw new RuntimeException('packed fixture was not created');
    }

    $marker = $root.'/config-executed';
    file_put_contents($repo.'/.git/config', "[core]\n\tfsmonitor = touch $marker\n[include]\n\tpath = $root/missing-config\n", FILE_APPEND);
    file_put_contents($repo.'/.git/hooks/post-checkout', "#!/bin/sh\ntouch $marker\n");
    putenv('UNRECOGNIZED_CREDENTIAL=fixture-secret');
    $approvedGit = ['path' => '/usr/bin/git', 'sha256' => hash_file('sha256', '/usr/bin/git')];
    $inspection = trustedProducerInspectSource($repo, $approved, $approvedGit);
    if (file_exists($marker) || ($inspection['source_sha'] ?? null) !== $source) {
        throw new RuntimeException('unsafe source config executed');
    }
    $packedRefs = $repo.'/.git/packed-refs';
    $originalRefs = (string) file_get_contents($packedRefs);
    if (preg_match('/^([0-9a-f]{40}) refs\/tags\/v2\.0\.0$/m', $originalRefs, $tagMatch) !== 1) {
        throw new RuntimeException('packed tag fixture missing');
    }
    $rejectPacked = static function () use ($repo, $approved, $approvedGit): void {
        try {
            trustedProducerInspectSource($repo, $approved, $approvedGit);
        } catch (RuntimeException) {
            return;
        }
        throw new LogicException('unsafe packed ref accepted');
    };
    file_put_contents($packedRefs, $originalRefs.$tagMatch[0]."\n");
    $rejectPacked();
    file_put_contents($packedRefs, str_replace($tagMatch[0], str_repeat('f', 40).' refs/tags/v2.0.0', $originalRefs));
    $rejectPacked();
    file_put_contents($packedRefs, str_replace('refs/tags/v2.0.0', 'refs/tags/v9.0.0', $originalRefs));
    $rejectPacked();
    unlink($packedRefs);
    symlink($root.'/missing-packed-refs', $packedRefs);
    $rejectPacked();
    unlink($packedRefs);
    file_put_contents($packedRefs, $originalRefs);
    // Git's loose ref takes precedence even when packed-refs differs.
    file_put_contents($repo.'/.git/refs/tags/v2.0.0', $tagMatch[1]."\n");
    file_put_contents($packedRefs, str_replace($tagMatch[0], str_repeat('f', 40).' refs/tags/v2.0.0', $originalRefs));
    trustedProducerInspectSource($repo, $approved, $approvedGit);
    unlink($repo.'/.git/refs/tags/v2.0.0');
    file_put_contents($packedRefs, $originalRefs);
    $index = $packIndex[0];
    $savedIndex = (string) file_get_contents($index);
    unlink($index);
    symlink($root.'/missing-index', $index);
    try {
        trustedProducerInspectSource($repo, $approved, $approvedGit);
        throw new LogicException('symlinked pack index accepted');
    } catch (RuntimeException) {
    }
    unlink($index);
    file_put_contents($index, $savedIndex);
    file_put_contents($index, substr_replace($savedIndex, chr(ord($savedIndex[20]) ^ 1), 20, 1));
    try {
        trustedProducerInspectSource($repo, $approved, $approvedGit);
        throw new LogicException('mismatched pack index accepted');
    } catch (RuntimeException) {
    }
    file_put_contents($index, $savedIndex);
    $lock = $root.'/resolution.lock';
    $bom = $root.'/resolved.cdx.json';
    file_put_contents($lock, json_encode(['packages' => [['name' => 'axiomasoft/azguard', 'version' => '2.0.0']]], JSON_THROW_ON_ERROR));
    file_put_contents($bom, json_encode(['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
        'metadata' => ['component' => ['type' => 'library', 'name' => 'axiomasoft/azguard', 'version' => '2.0.0',
            'purl' => 'pkg:composer/axiomasoft/azguard@2.0.0']], 'components' => []], JSON_THROW_ON_ERROR));
    $toolLock = json_decode((string) file_get_contents(__DIR__.'/../.github/security-tools.lock'), true, 512, JSON_THROW_ON_ERROR);
    $cyclone = $toolLock['tools']['cyclonedx-cli'];
    $paths = ['composer' => trim((string) shell_exec('command -v composer')), 'dependency_sbom' => $bom,
        'git' => '/usr/bin/git', 'lock' => $lock, 'normalizer' => __DIR__.'/../packages/core/.github/canonicalize-release-zip.py',
        'python' => realpath('/usr/bin/python3'), 'tar' => '/usr/bin/tar',
        'validator' => __DIR__.'/../var/tools/cyclonedx-cli-'.$cyclone['version'].'/cyclonedx-cli'];
    $inputs = [];
    foreach ($paths as $name => $path) {
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('required build fixture input unavailable: '.$name);
        }
        $inputs[$name] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }
    if (! hash_equals($cyclone['sha256'], $inputs['validator']['sha256'])) {
        throw new RuntimeException('validator digest mismatch');
    }
    mkdir($root.'/out1', 0700);
    mkdir($root.'/out2', 0700);
    $run = ['run_id' => '123', 'run_attempt' => '1'];
    $first = trustedProducerBuildOffline($repo, $approved, $inputs, $root.'/out1', $run);
    $second = trustedProducerBuildOffline($repo, $approved, $inputs, $root.'/out2', $run);
    if (json_encode($first, JSON_THROW_ON_ERROR) !== json_encode($second, JSON_THROW_ON_ERROR)
        || file_exists($marker) || ($first['qualification'] ?? null) !== 'nonrelease') {
        throw new RuntimeException('unsafe or nondeterministic isolated build');
    }
    foreach (['azguard-core-2.0.0.zip', 'source-core.cdx.json', 'sbom-core.cdx.json', 'resolution-core.lock', 'mapping-core.json', 'SHA256SUMS'] as $name) {
        if (file_get_contents($root.'/out1/'.$name) !== file_get_contents($root.'/out2/'.$name)) {
            throw new RuntimeException('non-deterministic artifact: '.$name);
        }
    }
    mkdir($root.'/out-cli', 0700);
    $config = ['repository' => $repo, 'identity' => $approved, 'inputs' => $inputs, 'output' => $root.'/out-cli',
        'source_run' => $run, 'approved_output' => $root.'/approved.json', 'predicate_output' => $root.'/predicate.json'];
    file_put_contents($root.'/config.json', json_encode($config, JSON_THROW_ON_ERROR));
    $pipeline = proc_open([PHP_BINARY, __DIR__.'/trusted-producer-pipeline.php', 'build', $root.'/config.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($pipeline) || proc_close($pipeline) !== 0
        || file_get_contents($root.'/out-cli/azguard-core-2.0.0.zip') !== file_get_contents($root.'/out1/azguard-core-2.0.0.zip')) {
        throw new RuntimeException('pipeline build fixture failed');
    }
    $config['identity']['split_sha'] = str_repeat('f', 40);
    $config['output'] = $root.'/out-bad-cli';
    $config['approved_output'] = $root.'/bad-approved.json';
    $config['predicate_output'] = $root.'/bad-predicate.json';
    mkdir($config['output'], 0700);
    file_put_contents($root.'/config.json', json_encode($config, JSON_THROW_ON_ERROR));
    $pipeline = proc_open([PHP_BINARY, __DIR__.'/trusted-producer-pipeline.php', 'build', $root.'/config.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($pipeline) || proc_close($pipeline) === 0 || file_exists($root.'/bad-predicate.json')) {
        throw new RuntimeException('pipeline accepted invalid split');
    }
    // Exercise the actual candidate workflow bridge with authenticated local
    // fixtures. Its credentialed fetch and both live guards are not run here.
    require_once __DIR__.'/../vendor/autoload.php';
    $workflow = Symfony\Component\Yaml\Yaml::parseFile(__DIR__.'/../docs/specs/trusted-producer.candidate.yml');
    $workflowRun = $workflow['jobs']['inspect']['steps'][2]['run'];
    $bridge = strstr($workflowRun, '# Independently authenticated graph/config provisioning is external.');
    if (! is_string($bridge)) {
        throw new RuntimeException('candidate workflow build bridge missing');
    }
    symlink($repo, $root.'/source');
    symlink(dirname(__DIR__), $root.'/trusted-workflow');
    $template = ['repository' => '__SOURCE_REPOSITORY__', 'identity' => $approved, 'inputs' => $inputs,
        'output' => $root.'/trusted-output', 'source_run' => $run,
        'approved_output' => $root.'/approved-evidence.json', 'predicate_output' => $root.'/producer-predicate.json'];
    $templatePath = $root.'/approved-producer.json';
    file_put_contents($templatePath, json_encode($template, JSON_THROW_ON_ERROR));
    $bridgeEnv = getenv();
    $bridgeEnv = [...$bridgeEnv, 'RUNNER_TEMP' => $root, 'TRUSTED_WORKFLOW_SHA' => $approved['workflow_sha'],
        'APPROVED_CONFIG_SHA256' => hash_file('sha256', $templatePath), 'APPROVED_LOCK_SHA256' => $inputs['lock']['sha256'],
        'APPROVED_SBOM_SHA256' => $inputs['dependency_sbom']['sha256'], 'CANDIDATE_SHA' => $source,
        'EXPECTED_SPLIT_SHA' => $split, 'CANDIDATE_TAG' => 'v2.0.0', 'SOURCE_RUN_ID' => '123', 'SOURCE_RUN_ATTEMPT' => '1'];
    $runBridge = static function (string $script) use ($root, $bridgeEnv): int {
        $process = proc_open(['bash', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w']], $pipes, $root, $bridgeEnv);
        return is_resource($process) ? proc_close($process) : throw new RuntimeException('cannot start workflow bridge');
    };
    file_put_contents($root.'/symlink-target', 'must-stay-unchanged');
    symlink($root.'/symlink-target', $root.'/effective-producer.json');
    if ($runBridge("set -Eeuo pipefail\nwork=\"$root\"\n".$bridge) !== 0
        || file_get_contents($root.'/trusted-output/azguard-core-2.0.0.zip') !== file_get_contents($root.'/out1/azguard-core-2.0.0.zip')
        || json_decode((string) file_get_contents($root.'/producer-predicate.json'), true, 512, JSON_THROW_ON_ERROR)['source_sha'] !== $source) {
        throw new RuntimeException('workflow build bridge fixture failed');
    }
    if (file_get_contents($root.'/symlink-target') !== 'must-stay-unchanged') {
        throw new RuntimeException('workflow bridge followed a predictable symlink');
    }
    packageRemoveFixtureDirectory($root.'/trusted-output');
    unlink($root.'/approved-evidence.json');
    unlink($root.'/producer-predicate.json');
    if ($runBridge("set -Eeuo pipefail\nwork=\"$root\"\nphp() { return 127; }\n".$bridge) === 0
        || file_exists($root.'/producer-predicate.json')) {
        throw new RuntimeException('workflow bridge ignored a missing build command');
    }
    packageRemoveFixtureDirectory($root.'/trusted-output');
    $collision = ['repository' => $repo, 'identity' => $approved, 'inputs' => $inputs, 'output' => $root.'/out-bad-cli',
        'source_run' => $run, 'approved_output' => $root.'/dangling-evidence', 'predicate_output' => $root.'/new-predicate'];
    symlink($root.'/nonexistent-evidence', $collision['approved_output']);
    file_put_contents($root.'/config.json', json_encode($collision, JSON_THROW_ON_ERROR));
    $pipeline = proc_open([PHP_BINARY, __DIR__.'/trusted-producer-pipeline.php', 'build', $root.'/config.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($pipeline) || proc_close($pipeline) === 0 || file_exists($root.'/nonexistent-evidence')) {
        throw new RuntimeException('pipeline followed dangling evidence symlink');
    }
    $collision['approved_output'] = $root.'/same-evidence';
    $collision['predicate_output'] = $root.'/./same-evidence';
    file_put_contents($root.'/config.json', json_encode($collision, JSON_THROW_ON_ERROR));
    $pipeline = proc_open([PHP_BINARY, __DIR__.'/trusted-producer-pipeline.php', 'build', $root.'/config.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($pipeline) || proc_close($pipeline) === 0 || file_exists($root.'/same-evidence')) {
        throw new RuntimeException('pipeline accepted colliding output paths');
    }
    $badIdentity = $approved;
    $badIdentity['split_sha'] = str_repeat('f', 40);
    try {
        trustedProducerInspectSource($repo, $badIdentity, $approvedGit);
        throw new LogicException('incorrect split accepted');
    } catch (RuntimeException) {
    }
    $badInputs = $inputs;
    $badInputs['lock']['sha256'] = str_repeat('f', 64);
    mkdir($root.'/out-bad', 0700);
    try {
        trustedProducerBuildOffline($repo, $approved, $badInputs, $root.'/out-bad', $run);
        throw new LogicException('incorrect lock digest accepted');
    } catch (RuntimeException) {
    }
    putenv('UNRECOGNIZED_CREDENTIAL');
    putenv('RELEASE_TAG_SIGNER_FINGERPRINT='.str_repeat('0', 40));
    try {
        trustedProducerInspectSource($repo, $approved, $approvedGit);
        throw new LogicException('isolated verifier accepted wrong fingerprint');
    } catch (RuntimeException) {
    }

    try {
        packageVerifySignedTag($repo, 'v2.0.0', $source);
        throw new LogicException('wrong trusted tag signer was accepted');
    } catch (RuntimeException) {
        // Expected rejection, with an actual generated signature and wrong key identity.
    }
    echo "Signed source tag fixture passed; no artifact attestation was created.\n";
} finally {
    try {
        packageEvidenceCommand(['gpgconf', '--kill', 'gpg-agent']);
    } catch (Throwable) {
        // The fixture directory is still removed below; no pass result is added.
    }
    foreach ($original as $name => $value) {
        $value === false ? putenv($name) : putenv($name.'='.$value);
    }
    packageRemoveFixtureDirectory($root);
}
