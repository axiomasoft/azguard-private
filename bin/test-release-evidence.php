<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

require __DIR__.'/verify-release-evidence.php';

$sha = str_repeat('a', 40);
$runId = '123-1';
$data = ['sha' => $sha, 'tag' => 'v2.0.0-rc.1', 'tag_sha' => $sha, 'run_id' => $runId,
    'jobs' => [], 'matrix' => [], 'scanners' => [], 'advisories' => [],
    'split_prerequisites' => ['archives' => true, 'sboms' => true, 'consumer_tests' => true]];
foreach (['tests', 'static', 'style', 'docs', 'nightly', 'compat', 'workers', 'security', 'codeql', 'zizmor', 'secret-scan', 'validate', 'validate-consumers', 'artifacts', 'advisories'] as $name) {
    $data['jobs'][$name] = ['result' => 'success'];
}
$names = [];
foreach (['8.3', '8.4', '8.5'] as $php) {
    foreach (['12', '13'] as $laravel) {
        if ($php === '8.5' && $laravel === '12') {
            continue;
        }
        foreach (['prefer-lowest', 'prefer-stable'] as $stability) {
            $names[] = "php{$php}-laravel{$laravel}-{$stability}";
        }
    }
}
foreach (['pgsql', 'mysql', 'mariadb'] as $db) {
    foreach (['12', '13'] as $laravel) {
        $names[] = "db-{$db}-laravel{$laravel}";
    }
}
$lock = '{"packages":[],"packages-dev":[]}';
foreach ($names as $name) {
    $data['matrix'][] = ['name' => $name, 'sha' => $sha, 'run_id' => $runId, 'result' => 'success', 'dependency_audit_passed' => true, 'lock' => $lock, 'lock_sha256' => hash('sha256', $lock)];
}
$scopes = [
    'codeql-actions' => 'CodeQL Actions extraction across the checked-out repository',
    'codeql-javascript-typescript' => 'CodeQL JavaScript/TypeScript extraction across the checked-out repository, including docs/.vitepress/config.ts',
    'zizmor' => 'GitHub Actions workflows in .github/workflows, packages/core/.github/workflows, and packages/filament/.github/workflows; pedantic, medium and higher',
    'gitleaks-worktree' => 'Gitleaks directory scan of the checked-out worktree using the pinned rules without allowlists',
    'gitleaks-history' => 'Gitleaks git patch scan of all commits reachable from the checked-out commit, including merge changes, using the pinned rules without allowlists',
];
foreach ($scopes as $name => $scope) {
    $run = ['tool' => ['driver' => ['name' => str_starts_with($name, 'codeql-') ? 'CodeQL' : 'zizmor']], 'results' => [], 'invocations' => [['executionSuccessful' => true]]];

    if (str_starts_with($name, 'codeql-')) {
        $run['automationDetails'] = ['id' => '/language:'.substr($name, 7).'/'];
    }
    $report = str_starts_with($name, 'gitleaks-') ? '[]' : json_encode(['version' => '2.1.0', 'runs' => [$run]], JSON_THROW_ON_ERROR);
    $data['scanners'][] = ['scanner' => $name, 'scope' => $scope, 'sha' => $sha, 'run_id' => $runId, 'outcome' => 'success', 'errors' => [], 'report' => $report, 'report_sha256' => hash('sha256', $report)];
}
foreach (['composer' => ['advisories' => [], 'abandoned' => []], 'npm' => ['auditReportVersion' => 2, 'vulnerabilities' => [], 'metadata' => ['vulnerabilities' => ['total' => 0]]], 'osv' => ['results' => []]] as $name => $body) {
    $report = json_encode($body, JSON_THROW_ON_ERROR);
    $data['advisories'][] = ['audit' => $name, 'sha' => $sha, 'run_id' => $runId, 'outcome' => 'success', 'report' => $report, 'report_sha256' => hash('sha256', $report)];
}

if (candidateSourceEvidenceErrors($data, $sha, $runId) !== []) {
    throw new RuntimeException('valid candidate fixture rejected');
}
foreach (['skipped', 'cancelled', 'failure'] as $status) {
    $bad = $data;
    $bad['jobs']['tests']['result'] = $status;

    if (candidateSourceEvidenceErrors($bad, $sha, $runId) === []) {
        throw new RuntimeException('bad job status accepted');
    }
}
$bad = $data;
array_pop($bad['matrix']);

if (candidateSourceEvidenceErrors($bad, $sha, $runId) === []
    || candidateSourceEvidenceErrors($data, str_repeat('b', 40), $runId) === []
    || candidateSourceEvidenceErrors($data, $sha, '123-2') === []) {
    throw new RuntimeException('partial, foreign or stale evidence accepted');
}
$path = tempnam(sys_get_temp_dir(), 'azguard-candidate-');

if ($path === false) {
    throw new RuntimeException('cannot create candidate fixture');
}

try {
    $invoke = static function (string $candidate, string $runId, string $path): int {
        $process = proc_open([PHP_BINARY, __DIR__.'/verify-release-evidence.php', '--candidate-source', $candidate, $runId, $path], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('cannot start candidate verifier');
        }

        return proc_close($process);
    };
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

    if ($invoke($sha, $runId, $path) !== 0 || $invoke(str_repeat('b', 40), $runId, $path) === 0) {
        throw new RuntimeException('candidate CLI identity validation failed');
    }
    file_put_contents($path, json_encode($bad, JSON_THROW_ON_ERROR));

    if ($invoke($sha, $runId, $path) === 0) {
        throw new RuntimeException('candidate CLI accepted partial rows');
    }
} finally {
    unlink($path);
}
foreach (['--candidate-artifacts'] as $mode) {
    $process = proc_open([PHP_BINARY, __DIR__.'/verify-release-evidence.php', $mode], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process) || proc_close($process) === 0) {
        throw new RuntimeException("unverified {$mode} accepted");
    }
}
$forged = tempnam(sys_get_temp_dir(), 'azguard-setup-');

if ($forged === false) {
    throw new RuntimeException('cannot create setup fixture');
}

try {
    file_put_contents($forged, '{"status":"ready","attestations":true,"all_settings_verified":true}');
    foreach ([['--setup', $forged], ['--setup='.$forged, '--read-only', '--strict']] as $setupArgs) {
        if (releaseEvidenceSetupPath($setupArgs) !== $forged) {
            throw new RuntimeException('valid setup arguments rejected');
        }
        $process = proc_open([PHP_BINARY, __DIR__.'/verify-release-evidence.php', ...$setupArgs], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('cannot start setup verifier');
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        if (proc_close($process) === 0 || ! is_string($error) || ! str_contains($error, 'invalid setup request identity') || str_contains($error, 'usage:')) {
            throw new RuntimeException('self-asserted setup proof accepted or setup CLI failed to reach verification');
        }
    }

    if (releaseEvidenceSetupPath(['--setup', '--strict', '--read-only']) !== dirname(__DIR__).'/build/release/setup-request.json'
        || releaseEvidenceSetupPath(['--candidate-source']) !== null) {
        throw new RuntimeException('setup defaults or candidate mode rejected');
    }
    foreach ([['--setup='], ['--setup='.$forged, '--strict', '--strict'], ['--setup='.$forged, 'other'], ['--setup', '--write']] as $badArgs) {
        try {
            releaseEvidenceSetupPath($badArgs);

            throw new LogicException('invalid setup arguments accepted');
        } catch (RuntimeException) {
            // A malformed request must fail before any read-only API calls.
        }
    }
} finally {
    unlink($forged);
}
echo "Candidate evidence fixtures passed.\n";

require dirname(__DIR__).'/vendor/autoload.php';
$workflow = Yaml::parseFile(dirname(__DIR__).'/.github/workflows/release.yml');

if (isset($workflow['jobs']['distribution-policy']) || ! in_array('gate', $workflow['jobs']['split']['needs'] ?? [], true)
    || ! in_array('provenance', $workflow['jobs']['release']['needs'] ?? [], true)
    || ! in_array('publish-packages', $workflow['jobs']['release']['needs'] ?? [], true)) {
    throw new RuntimeException('release graph does not enforce qualification, provenance and split publication');
}
$steps = $workflow['jobs']['validate']['steps'];
$tagSteps = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? null) === 'Require an annotated tag'));

if (count($tagSteps) !== 1 || ! is_string($tagSteps[0]['run'] ?? null)) {
    throw new RuntimeException('missing tag verification step');
}
$dir = sys_get_temp_dir().'/azguard-tag-'.bin2hex(random_bytes(8));
mkdir($dir);
mkdir($dir.'/keys', 0700);
mkdir($dir.'/repo');
$env = getenv();
$env['GNUPGHOME'] = $dir.'/keys';
$env['GIT_CONFIG_GLOBAL'] = '/dev/null';
$env['GIT_CONFIG_NOSYSTEM'] = '1';
$run = static function (array $command, string $cwd, array $environment): array {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd, $environment);

    if (! is_resource($process)) {
        throw new RuntimeException('fixture command did not start');
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), trim((string) $out)];
};
$must = static function (array $command, string $cwd, array $environment) use ($run): string {
    [$exit, $out] = $run($command, $cwd, $environment);

    if ($exit !== 0) {
        throw new RuntimeException('fixture command failed: '.$command[0]);
    }

    return $out;
};

try {
    $must(['gpg', '--batch', '--pinentry-mode', 'loopback', '--passphrase', '', '--quick-generate-key', 'Fixture <fixture@example.invalid>', 'ed25519', 'sign', '0'], $dir, $env);
    $listing = $must(['gpg', '--batch', '--with-colons', '--fingerprint'], $dir, $env);

    if (! preg_match('/^fpr:::::::::([0-9A-F]{40}):/m', $listing, $match)) {
        throw new RuntimeException('missing fixture fingerprint');
    }
    $fingerprint = $match[1];
    $publicKey = $must(['gpg', '--batch', '--armor', '--export', $fingerprint], $dir, $env);
    $repo = $dir.'/repo';
    $must(['git', 'init', '-q'], $repo, $env);
    $must(['git', 'config', 'user.name', 'Fixture'], $repo, $env);
    $must(['git', 'config', 'user.email', 'fixture@example.invalid'], $repo, $env);
    mkdir($repo.'/packages/core', 0777, true);
    mkdir($repo.'/packages/filament', 0777, true);
    file_put_contents($repo.'/packages/core/composer.json', '{"name":"axiomasoft/azguard","description":"fixture","type":"library"}');
    file_put_contents($repo.'/packages/filament/composer.json', '{"name":"axiomasoft/azguard-filament","description":"fixture","type":"library"}');
    $must(['git', 'add', 'packages/core', 'packages/filament'], $repo, $env);
    $must(['git', 'commit', '-qm', 'fixture'], $repo, $env);
    $sha = $must(['git', 'rev-parse', 'HEAD'], $repo, $env);
    $must(['git', '-c', 'user.signingkey='.$fingerprint, 'tag', '-s', 'v2.0.0', '-m', 'fixture'], $repo, $env);
    $tagEnv = $env;
    $tagEnv['TAG'] = 'v2.0.0';
    $tagEnv['TAGGED_SHA'] = $sha;
    $tagEnv['RELEASE_TAG_PUBLIC_KEY'] = $publicKey;
    $tagEnv['RELEASE_TAG_SIGNER_FINGERPRINT'] = $fingerprint;

    if ($run(['bash', '-c', $tagSteps[0]['run']], $repo, $tagEnv)[0] !== 0) {
        throw new RuntimeException('trusted signed tag rejected');
    }
    $tagEnv['RELEASE_TAG_SIGNER_FINGERPRINT'] = str_repeat('0', 40);

    if ($run(['bash', '-c', $tagSteps[0]['run']], $repo, $tagEnv)[0] === 0) {
        throw new RuntimeException('untrusted tag signer accepted');
    }

    $splitSha = $must(['git', 'subtree', 'split', '--prefix=packages/core', 'HEAD'], $repo, $env);
    $must(['git', 'clone', '-q', '--no-checkout', $repo, $dir.'/split'], $dir, $env);
    $must(['git', 'checkout', '-q', '--detach', $splitSha], $dir.'/split', $env);
    mkdir($dir.'/bin');
    file_put_contents($dir.'/bin/gh', "#!/usr/bin/env bash\nif [[ \"\$*\" == *jobs* ]]; then printf '%s\\n' \"\$FIXTURE_JOBS\"; else printf '%s\\n' \"\$FIXTURE_RUN\"; fi\n");
    chmod($dir.'/bin/gh', 0700);
    $config = $dir.'/gitconfig';
    $must(['git', 'config', '--file', $config, 'url.file://'.$repo.'.insteadOf', 'https://x-access-token@github.com/axiomasoft/azguard-private.git'], $dir, $env);
    $sourceEnv = $env;
    $sourceEnv['GIT_CONFIG_GLOBAL'] = $config;
    $sourceEnv['PATH'] = $dir.'/bin:'.$env['PATH'];
    $sourceEnv['GITHUB_REF'] = 'refs/heads/release-candidate/v2.0.0/123/1';
    $sourceEnv['GITHUB_REPOSITORY'] = 'axiomasoft/azguard';
    $sourceEnv['GITHUB_SHA'] = $splitSha;
    $sourceEnv['GH_TOKEN'] = 'fixture';
    mkdir($dir.'/source-temp');
    $sourceEnv['RUNNER_TEMP'] = $dir.'/source-temp';
    $sourceEnv['RELEASE_TAG_PUBLIC_KEY'] = $publicKey;
    $sourceEnv['RELEASE_TAG_SIGNER_FINGERPRINT'] = $fingerprint;
    $runData = ['id' => 123, 'run_attempt' => 1, 'head_branch' => 'v2.0.0', 'head_sha' => $sha, 'event' => 'push', 'repository' => ['full_name' => 'axiomasoft/azguard-private'], 'path' => '.github/workflows/release.yml', 'status' => 'in_progress'];
    $job = ['id' => 10, 'name' => 'Release evidence gate', 'head_sha' => $sha, 'run_id' => 123, 'status' => 'completed', 'conclusion' => 'success', 'steps' => [['name' => 'Evaluate release evidence', 'status' => 'completed', 'conclusion' => 'success']]];
    $sourceEnv['FIXTURE_RUN'] = json_encode($runData, JSON_THROW_ON_ERROR);
    $sourceEnv['FIXTURE_JOBS'] = json_encode([['total_count' => 1, 'jobs' => [$job]]], JSON_THROW_ON_ERROR);
    $producer = dirname(__DIR__).'/packages/core/.github/verify-release-source.sh';

    if ($run(['bash', $producer], $dir.'/split', $sourceEnv)[0] !== 0) {
        throw new RuntimeException('valid public producer mapping rejected');
    }

    if ($must(['git', 'rev-parse', 'HEAD'], $dir.'/source-temp/verified-release-source', $env) !== $sha) {
        throw new RuntimeException('verified source checkout has wrong commit');
    }
    $badEnv = $sourceEnv;
    $badEnv['RELEASE_TAG_SIGNER_FINGERPRINT'] = str_repeat('0', 40);

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted an untrusted signer');
    }
    $badEnv = $sourceEnv;
    $job['conclusion'] = 'skipped';
    $badEnv['FIXTURE_JOBS'] = json_encode([['total_count' => 1, 'jobs' => [$job]]], JSON_THROW_ON_ERROR);

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted a failed gate');
    }
    $badEnv = $sourceEnv;
    $badEnv['FIXTURE_JOBS'] = json_encode([['total_count' => 2, 'jobs' => [['id' => 10, 'name' => 'Release evidence gate', 'head_sha' => $sha, 'run_id' => 123, 'status' => 'completed', 'conclusion' => 'success', 'steps' => [['name' => 'Evaluate release evidence', 'status' => 'completed', 'conclusion' => 'success']]]]]], JSON_THROW_ON_ERROR);

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted partial job pages');
    }
    $badEnv = $sourceEnv;
    $badEnv['GITHUB_REF'] = 'refs/heads/release-candidate/v2.0.0/123/2';

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted a stale run attempt');
    }
    $badEnv = $sourceEnv;
    $badEnv['GH_TOKEN'] = '';

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted missing read entitlement');
    }
    $badEnv = $sourceEnv;
    $runData['head_sha'] = str_repeat('b', 40);
    $badEnv['FIXTURE_RUN'] = json_encode($runData, JSON_THROW_ON_ERROR);

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted a foreign candidate');
    }
    $must(['git', 'config', 'user.name', 'Fixture'], $dir.'/split', $env);
    $must(['git', 'config', 'user.email', 'fixture@example.invalid'], $dir.'/split', $env);
    file_put_contents($dir.'/split/extra', 'wrong tree');
    $must(['git', 'add', 'extra'], $dir.'/split', $env);
    $must(['git', 'commit', '-qm', 'wrong split'], $dir.'/split', $env);
    $badEnv = $sourceEnv;
    $badEnv['GITHUB_SHA'] = $must(['git', 'rev-parse', 'HEAD'], $dir.'/split', $env);

    if ($run(['bash', $producer], $dir.'/split', $badEnv)[0] === 0) {
        throw new RuntimeException('producer accepted a different split tree');
    }

    $artifactDir = $dir.'/artifacts';
    mkdir($artifactDir);
    $splitIds = [];
    foreach (['core' => 'axiomasoft/azguard'] as $package => $name) {
        $packageDir = $dir.'/build-'.$package;
        $buildDir = $dir.'/zip-'.$package;
        mkdir($packageDir);
        mkdir($buildDir);
        $composer = json_decode((string) file_get_contents($repo.'/packages/'.$package.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['version'] = '2.0.0';
        file_put_contents($packageDir.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $must(['composer', 'archive', '--format=zip', '--dir='.$buildDir, '--working-dir='.$packageDir, '--no-interaction'], $dir, $env);
        $archives = glob($buildDir.'/*.zip') ?: [];

        if (count($archives) !== 1) {
            throw new RuntimeException('fixture ZIP missing');
        }
        $zip = $artifactDir.'/azguard-'.$package.'-2.0.0.zip';
        copy($archives[0], $zip);
        file_put_contents($artifactDir.'/source-'.$package.'.cdx.json', json_encode(packageSourceInventory($zip, $name, '2.0.0'), JSON_THROW_ON_ERROR));
        $bom = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
            'metadata' => ['component' => ['type' => 'library', 'name' => $name, 'version' => '2.0.0']], 'components' => []];
        file_put_contents($artifactDir.'/sbom-'.$package.'.cdx.json', json_encode($bom, JSON_THROW_ON_ERROR));
        file_put_contents($artifactDir.'/resolution-'.$package.'.lock', json_encode(['packages' => [['name' => $name, 'version' => '2.0.0']], 'packages-dev' => []], JSON_THROW_ON_ERROR));
        $identity = ['repository' => 'axiomasoft/azguard-private', 'source_sha' => $sha, 'tag' => 'v2.0.0', 'workflow_sha' => $sha, 'run_id' => '123', 'run_attempt' => '1'];
        $mapping = packageBuildManifest($repo, $package, '2.0.0', $artifactDir, $identity);
        $splitIds[$package] = $mapping['split_commit'];
        file_put_contents($artifactDir.'/mapping-'.$package.'.json', json_encode($mapping, JSON_THROW_ON_ERROR));
    }
    $files = ['azguard-core-2.0.0.zip', 'source-core.cdx.json', 'sbom-core.cdx.json', 'resolution-core.lock', 'mapping-core.json'];
    $writeChecksums = static function () use ($artifactDir, $files): void {
        $lines = '';
        foreach ($files as $file) {
            $lines .= hash_file('sha256', $artifactDir.'/'.$file).'  '.$file."\n";
        }
        file_put_contents($artifactDir.'/SHA256SUMS', $lines);
    };
    $writeChecksums();
    $oldKey = getenv('RELEASE_TAG_PUBLIC_KEY');
    $oldFingerprint = getenv('RELEASE_TAG_SIGNER_FINGERPRINT');
    putenv('RELEASE_TAG_PUBLIC_KEY='.$publicKey);
    putenv('RELEASE_TAG_SIGNER_FINGERPRINT='.$fingerprint);

    try {
        $calls = 0;
        $fakeVerify = static function (array $command, array $expected) use (&$calls, $splitIds): bool {
            $calls++;
            $repo = $command[5] ?? '';
            $package = $repo === 'axiomasoft/azguard' ? 'core' : '';

            if ($package === '' || ($command[7] ?? null) !== $repo.'/.github/workflows/provenance.yml'
                || ($command[9] ?? null) !== $splitIds[$package] || ($command[11] ?? null) !== $splitIds[$package]
                || ($command[13] ?? null) !== 'refs/heads/release-candidate/v2.0.0/123/1'
                || ! in_array($command[15] ?? null, ['https://slsa.dev/provenance/v1', 'https://cyclonedx.org/bom', 'https://axiomasoft.github.io/azguard/attestations/package-mapping/v1'], true)) {
                return false;
            }
            $items = $expected === [] ? [(object) ['fixture' => true]] : $expected;
            $responses = array_map(static fn (mixed $predicate): array => ['verificationResult' => ['statement' => ['predicate' => $predicate]]], $items);

            return packageAttestationPredicatesMatch(json_encode($responses, JSON_THROW_ON_ERROR), $expected);
        };
        packageVerifyAttestations($repo, $sha, 'v2.0.0', $artifactDir, $splitIds['core'], '123-1', $fakeVerify);

        if ($calls !== 3) {
            throw new RuntimeException('missing required attestation type');
        }
        $reject = static function (callable $test): void {
            try {
                $test();
            } catch (Throwable) {
                return;
            }

            throw new RuntimeException('invalid artifact evidence accepted');
        };
        file_put_contents($artifactDir.'/azguard-filament-2.0.0.zip', 'unpublished');
        $reject(static fn () => packageVerifyAttestations($repo, $sha, 'v2.0.0', $artifactDir, $splitIds['core'], '123-1', $fakeVerify));
        unlink($artifactDir.'/azguard-filament-2.0.0.zip');
        $reject(static fn () => packageVerifyAttestations($repo, $sha, 'v2.0.0', $artifactDir, $splitIds['core'], '123-2', $fakeVerify));
        $reject(static fn () => packageVerifyAttestations($repo, $sha, 'v2.0.0', $artifactDir, $splitIds['core'], '123-1', static fn (): bool => false));
        $source = $artifactDir.'/source-core.cdx.json';
        file_put_contents($source, '{}');
        $writeChecksums();
        $reject(static fn () => packageVerifyAttestations($repo, $sha, 'v2.0.0', $artifactDir, $splitIds['core'], '123-1', $fakeVerify));

        if (packageAttestationPredicatesMatch('[{"verificationResult":{"statement":{"predicate":{"v":1}}}}]', [(object) ['v' => '1']])) {
            throw new RuntimeException('predicate comparison accepted different types');
        }

        if (packageAttestationPredicatesMatch('[{}]', [])) {
            throw new RuntimeException('empty verification statement accepted');
        }
    } finally {
        $oldKey === false ? putenv('RELEASE_TAG_PUBLIC_KEY') : putenv('RELEASE_TAG_PUBLIC_KEY='.$oldKey);
        $oldFingerprint === false ? putenv('RELEASE_TAG_SIGNER_FINGERPRINT') : putenv('RELEASE_TAG_SIGNER_FINGERPRINT='.$oldFingerprint);
    }
    $must(['git', 'tag', '-a', 'v2.0.1', '-m', 'unsigned'], $repo, $env);
    $tagEnv['TAG'] = 'v2.0.1';
    $tagEnv['RELEASE_TAG_SIGNER_FINGERPRINT'] = $fingerprint;

    if ($run(['bash', '-c', $tagSteps[0]['run']], $repo, $tagEnv)[0] === 0) {
        throw new RuntimeException('unsigned annotated tag accepted');
    }

    $now = time();
    $observations = ['observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now), 'repositories' => []];
    $apiData = [];
    $setupHeads = [];
    $i = 0;
    foreach (releaseSetupPaths() as $repository => $paths) {
        $i++;
        $head = str_repeat((string) $i, 40);
        $setupHeads[$repository] = $head;
        $observations['repositories'][$repository] = ['status' => 'unavailable', 'detail' => 'not independently observed'];
        $apiData['repos/'.$repository] = ['full_name' => $repository, 'private' => false, 'default_branch' => 'main', 'archived' => false];
        $apiData['repos/'.$repository.'/commits/main'] = ['sha' => $head];
        foreach ($paths as $target => $source) {
            $apiData['repos/'.$repository.'/contents/'.$target.'?ref='.$head] = ['encoding' => 'base64', 'content' => base64_encode((string) file_get_contents(dirname(__DIR__).'/'.$source))];
        }
        $apiData['repos/'.$repository.'/actions/variables/RELEASE_TAG_SIGNER_FINGERPRINT'] = ['value' => $fingerprint];
        $apiData['repos/'.$repository.'/actions/variables/RELEASE_TAG_PUBLIC_KEY'] = ['value' => $publicKey];
        $isSource = $repository === 'axiomasoft/azguard-private';

        if (! $isSource) {
            $apiData['repos/'.$repository.'/actions/secrets/PRIVATE_RELEASE_READ_TOKEN'] = ['name' => 'PRIVATE_RELEASE_READ_TOKEN'];
        }
        $workflow = $isSource ? 'release-setup-probe.yml' : 'attestation-setup.yml';
        $jobName = $isSource ? 'Verify split publication prerequisites' : 'Verify split attestation prerequisites';
        $stepNames = $isSource ? ['Verify setup read token', 'Probe split push and dispatch permissions'] : ['Verify source read access', 'Confirm public source', 'Create setup probe', 'Attest setup probe'];
        $apiData['repos/'.$repository.'/actions/workflows/'.$workflow] = ['id' => 100 + $i, 'state' => 'active', 'path' => '.github/workflows/'.$workflow];
        $apiData['repos/'.$repository.'/actions/workflows/'.$workflow.'/runs?branch=main&event=workflow_dispatch&status=completed&per_page=100'] = ['workflow_runs' => [[
            'id' => 200 + $i, 'run_attempt' => 1, 'workflow_id' => 100 + $i, 'head_sha' => $head, 'head_branch' => 'main',
            'repository' => ['full_name' => $repository], 'event' => 'workflow_dispatch', 'status' => 'completed', 'conclusion' => 'success', 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ]]];
        $apiData['repos/'.$repository.'/actions/runs/'.(200 + $i).'/attempts/1/jobs?per_page=100'] = [['total_count' => 1, 'jobs' => [[
            'id' => 300 + $i, 'name' => $jobName, 'run_id' => 200 + $i, 'head_sha' => $head, 'status' => 'completed', 'conclusion' => 'success',
            'steps' => array_map(static fn (string $step): array => ['name' => $step, 'status' => 'completed', 'conclusion' => 'success'], $stepNames),
        ]]]];
    }
    $apiData['repos/axiomasoft/azguard-private/environments/release'] = ['name' => 'release', 'deployment_branch_policy' => null, 'protection_rules' => [['type' => 'required_reviewers', 'prevent_self_review' => false, 'reviewers' => [['type' => 'User', 'reviewer' => ['id' => 3863273]]]]]];
    $apiData['repos/axiomasoft/azguard-private/actions/secrets/MONOREPO_SPLIT_TOKEN'] = ['name' => 'MONOREPO_SPLIT_TOKEN'];
    $apiData['repos/axiomasoft/azguard-private/actions/secrets/RELEASE_SETUP_READ_TOKEN'] = ['name' => 'RELEASE_SETUP_READ_TOKEN'];
    $apiData['repos/axiomasoft/azguard-private/actions/variables/SPLIT_ENABLED'] = ['name' => 'SPLIT_ENABLED', 'value' => 'true'];
    $request = ['schema' => 'azguard.release.setup-request.v1', 'candidate_sha' => null, 'remote_changes_authorized' => false,
        'status' => 'deferred reference only; no split or publication authorized',
        'observations' => $observations, 'proposal' => releaseSetupProposal(dirname(__DIR__))];

    if (($request['proposal']['execution_scope'] ?? null) !== 'local-only; no split or publication'
        || ($request['proposal']['remote_proposal_status'] ?? null) !== 'deferred reference only; no configuration, split or publication is authorized'
        || $request['proposal']['split_repositories'] !== []
        || $request['proposal']['payloads'] !== [] || $request['proposal']['secrets'] !== []
        || $request['proposal']['variables'] !== [] || $request['proposal']['environment'] !== null
        || $request['proposal']['deferred_reference']['split_repositories'] !== ['axiomasoft/azguard']
        || isset(releaseSetupPaths()['axiomasoft/azguard-filament'])) {
        throw new RuntimeException('setup proposal includes an unpublished Filament split');
    }
    $setupFormat = json_decode((string) file_get_contents(__DIR__.'/release-setup-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $setupFixture = json_decode((string) file_get_contents(__DIR__.'/release-setup-compat.json'), true, 512, JSON_THROW_ON_ERROR);

    if ($setupFormat['schema'] !== $request['schema'] || array_keys($setupFixture) !== $setupFormat['required']
        || $setupFixture['candidate_sha'] !== null || $setupFixture['remote_changes_authorized'] !== false
        || releaseSetupRequestErrors($setupFixture, dirname(__DIR__)) === []) {
        throw new RuntimeException('setup request compatibility fixture failed');
    }
    $api = static fn (string $endpoint, bool $paginate = false): ?array => $apiData[$endpoint] ?? null;
    $attest = static fn (string $repository, string $head): bool => isset($setupHeads[$repository]) && $setupHeads[$repository] === $head;
    $audit = static fn (): bool => true;
    $trustBlock = 'trusted producer control is not established; release setup is blocked';
    $scopeBlock = 'current scope forbids split and publication; release setup is blocked';
    $actualErrors = releaseSetupLiveErrors($request, dirname(__DIR__), $api, $attest, $audit, $now);

    if (! in_array($trustBlock, $actualErrors, true) || ! in_array($scopeBlock, $actualErrors, true)) {
        throw new RuntimeException('setup accepted despite the no-split scope or without trusted producer control');
    }
    // Isolate other negative checks without ever allowing the actual gate to pass.
    $liveErrors = static fn (...$args): array => array_values(array_diff(releaseSetupLiveErrors(...$args), [$trustBlock, $scopeBlock]));
    $errors = $liveErrors($request, dirname(__DIR__), $api, $attest, $audit, $now);

    if ($errors !== []) {
        throw new RuntimeException('valid setup fixture rejected: '.implode('; ', $errors));
    }
    $privateApiData = $apiData;
    $privateApiData['repos/axiomasoft/azguard-private']['private'] = true;
    $privateApi = static fn (string $endpoint, bool $paginate = false): ?array => $privateApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $privateApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('public-source probe accepted after source became private');
    }
    foreach (['azguard' => 202] as $split => $id) {
        $endpoint = 'repos/axiomasoft/'.$split.'/actions/runs/'.$id.'/attempts/1/jobs?per_page=100';
        $privateApiData[$endpoint][0]['jobs'][0]['steps'][1]['name'] = 'Confirm private source';
    }
    $privateApi = static fn (string $endpoint, bool $paginate = false): ?array => $privateApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $privateApi, $attest, $audit, $now) !== []) {
        throw new RuntimeException('fresh private-source probes rejected');
    }
    $publicAfterPrivate = $privateApiData;
    $publicAfterPrivate['repos/axiomasoft/azguard-private']['private'] = false;
    $publicApi = static fn (string $endpoint, bool $paginate = false): ?array => $publicAfterPrivate[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $publicApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('private-source probe accepted after source became public');
    }
    $unknownVisibility = $apiData;
    unset($unknownVisibility['repos/axiomasoft/azguard-private']['private']);
    $unknownApi = static fn (string $endpoint, bool $paginate = false): ?array => $unknownVisibility[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $unknownApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('source with unknown visibility accepted');
    }
    $falseRequest = $request;
    $falseRequest['proposal']['execution_scope'] = 'release';

    if ($liveErrors($falseRequest, dirname(__DIR__), $api, $attest, $audit, $now) === []) {
        throw new RuntimeException('setup request accepted an unauthorized release scope');
    }
    foreach (['split_repositories', 'payloads', 'secrets', 'variables'] as $field) {
        $falseRequest = $request;
        $falseRequest['proposal'][$field][] = 'unauthorized';

        if ($liveErrors($falseRequest, dirname(__DIR__), $api, $attest, $audit, $now) === []) {
            throw new RuntimeException('setup request accepted unauthorized current '.$field);
        }
    }
    $falseRequest = $request;
    $falseRequest['proposal']['deferred_reference']['payloads'][0]['sha256'] = str_repeat('0', 64);

    if ($liveErrors($falseRequest, dirname(__DIR__), $api, $attest, $audit, $now) === []) {
        throw new RuntimeException('altered setup request accepted');
    }
    $falseRequest = $request;
    $falseRequest['proposal']['distribution_policy']['github_snapshots'] = 'provider snapshots are signed release assets';

    if ($liveErrors($falseRequest, dirname(__DIR__), $api, $attest, $audit, $now) === []) {
        throw new RuntimeException('false provider snapshot claim accepted');
    }
    $falseRequest = $request;
    $falseRequest['proposal']['split_repositories'][] = 'axiomasoft/azguard-filament';

    if ($liveErrors($falseRequest, dirname(__DIR__), $api, $attest, $audit, $now) === []) {
        throw new RuntimeException('setup request accepted unpublished Filament split');
    }
    $badApiData = $apiData;
    $jobEndpoint = 'repos/axiomasoft/azguard/actions/runs/202/attempts/1/jobs?per_page=100';
    $badApiData[$jobEndpoint][0]['total_count'] = 2;
    $badApi = static fn (string $endpoint, bool $paginate = false): ?array => $badApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $badApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('partial setup job proof accepted');
    }
    $badApiData = $apiData;
    $badApiData[$jobEndpoint][0]['jobs'][0]['steps'][0]['conclusion'] = 'skipped';
    $badApi = static fn (string $endpoint, bool $paginate = false): ?array => $badApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $badApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('skipped setup proof accepted');
    }
    $badApiData = $apiData;
    $badApiData[$jobEndpoint][0]['jobs'][0]['head_sha'] = str_repeat('f', 40);
    $badApi = static fn (string $endpoint, bool $paginate = false): ?array => $badApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $badApi, $attest, $audit, $now) === []
        || $liveErrors($request, dirname(__DIR__), $api, static fn (): bool => false, $audit, $now) === []
        || $liveErrors($request, dirname(__DIR__), $api, $attest, $audit, $now + 7200) === []) {
        throw new RuntimeException('foreign, untrusted or stale setup proof accepted');
    }
    $badApiData = $apiData;
    $badApiData['repos/axiomasoft/azguard-private/environments/release']['protection_rules'][0]['reviewers'][0]['reviewer']['id'] = 999;
    $badApiData['repos/axiomasoft/azguard-private/actions/variables/SPLIT_ENABLED']['value'] = 'false';
    $badApi = static fn (string $endpoint, bool $paginate = false): ?array => $badApiData[$endpoint] ?? null;

    if ($liveErrors($request, dirname(__DIR__), $badApi, $attest, $audit, $now) === []) {
        throw new RuntimeException('wrong release reviewer and disabled split accepted');
    }
    $setupWorkflow = Yaml::parseFile(dirname(__DIR__).'/.github/workflows/release-setup-probe.yml');
    $setupSteps = $setupWorkflow['jobs']['probe']['steps'];

    if (($setupSteps[0]['name'] ?? '') !== 'Require independently trusted producer' || $run(['bash', '-c', $setupSteps[0]['run']], $dir, $env)[0] === 0) {
        throw new RuntimeException('source setup workflow guard did not fail closed');
    }
    $probeRun = $setupSteps[2]['run'];

    if (str_contains($setupSteps[1]['run'], 'azguard-filament') || str_contains($probeRun, 'azguard-filament')) {
        throw new RuntimeException('setup probe addresses the unpublished Filament split');
    }
    mkdir($dir.'/setup-bin');
    file_put_contents($dir.'/setup-bin/gh', "#!/usr/bin/env bash\nif [[ \"\$*\" == *\"\${FIXTURE_FAIL_REPO:-not-a-repo}\"* ]]; then exit 1; fi\nif [[ \"\$*\" == *'/contents/'* && \"\${FIXTURE_DENY_CONTENTS:-0}\" == 1 ]]; then exit 1; fi\nif [[ \"\$*\" == *'/contents/'* && \"\${FIXTURE_BAD_CONTENTS:-0}\" == 1 ]]; then printf 'false\\n'; exit 0; fi\nif [[ \"\$1\" == api ]]; then printf 'true\\n'; fi\nexit 0\n");
    chmod($dir.'/setup-bin/gh', 0700);
    $probeConfig = $dir.'/probe-gitconfig';
    foreach (['azguard'] as $splitRepo) {
        $remote = $dir.'/'.$splitRepo.'.git';
        $must(['git', 'init', '--bare', '-q', $remote], $dir, $env);
        $must(['git', 'config', '--file', $probeConfig, 'url.file://'.$remote.'.insteadOf', 'https://x-access-token@github.com/axiomasoft/'.$splitRepo.'.git'], $dir, $env);
    }
    $probeEnv = $env;
    $probeEnv['PATH'] = $dir.'/setup-bin:'.$env['PATH'];
    $probeEnv['GIT_CONFIG_GLOBAL'] = $probeConfig;
    $probeEnv['GITHUB_RUN_ID'] = '123';
    $probeEnv['GITHUB_RUN_ATTEMPT'] = '1';
    $probeEnv['GH_TOKEN'] = 'fixture';
    $probeEnv['GITHUB_OUTPUT'] = $dir.'/github-output';

    if ($run(['bash', '-c', $probeRun], $dir, $probeEnv)[0] !== 0) {
        throw new RuntimeException('local setup permission probe failed');
    }
    foreach (['azguard'] as $splitRepo) {
        if ($run(['git', '--git-dir='.$dir.'/'.$splitRepo.'.git', 'show-ref', '--verify', 'refs/heads/release-setup-probe/123/1'], $dir, $env)[0] === 0) {
            throw new RuntimeException('setup probe left a temporary ref');
        }
    }
    $probeEnv['FIXTURE_FAIL_REPO'] = 'azguard';

    if ($run(['bash', '-c', $probeRun], $dir, $probeEnv)[0] === 0) {
        throw new RuntimeException('setup probe ignored dispatch failure');
    }
    foreach (['azguard'] as $splitRepo) {
        if ($run(['git', '--git-dir='.$dir.'/'.$splitRepo.'.git', 'show-ref', '--verify', 'refs/heads/release-setup-probe/123/1'], $dir, $env)[0] === 0) {
            throw new RuntimeException('failed setup probe left a temporary ref');
        }
    }
    unset($probeEnv['FIXTURE_FAIL_REPO']);
    $must([PHP_BINARY, __DIR__.'/test-release-setup-source-read.php'], $dir, $env);
} finally {
    $run(['gpgconf', '--kill', 'gpg-agent'], $dir, $env);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}
echo "Signed tag, producer mapping and setup verifier fixtures passed.\n";
