<?php

declare(strict_types=1);

/** Fixed reviewable payload: keys are remote paths and values are local sources. */
function releaseSetupPaths(): array
{
    return [
        'axiomasoft/azguard-private' => [
            '.github/workflows/release.yml' => '.github/workflows/release.yml',
            '.github/workflows/split.yml' => '.github/workflows/split.yml',
            '.github/workflows/release-setup-probe.yml' => '.github/workflows/release-setup-probe.yml',
            '.github/security-tools.lock' => '.github/security-tools.lock',
            'bin/package-evidence.php' => 'bin/package-evidence.php',
            'bin/provider-archive-evidence.py' => 'bin/provider-archive-evidence.py',
            'bin/provider-archive-format.json' => 'bin/provider-archive-format.json',
            'bin/provider-archive-compat.json' => 'bin/provider-archive-compat.json',
            'bin/prepare-release-setup.php' => 'bin/prepare-release-setup.php',
            'bin/collect-release-evidence.php' => 'bin/collect-release-evidence.php',
            'bin/release-gate.php' => 'bin/release-gate.php',
            'bin/release-artifacts.sh' => 'bin/release-artifacts.sh',
            'bin/repository-settings-audit.php' => 'bin/repository-settings-audit.php',
            'bin/security-tools.sh' => 'bin/security-tools.sh',
            'bin/verify-release-evidence.php' => 'bin/verify-release-evidence.php',
            'bin/verify-release-setup.php' => 'bin/verify-release-setup.php',
            'bin/verify-package-attestations.php' => 'bin/verify-package-attestations.php',
            'bin/verify-release-split.sh' => 'bin/verify-release-split.sh',
        ],
        'axiomasoft/azguard' => [
            '.github/workflows/provenance.yml' => 'packages/core/.github/workflows/provenance.yml',
            '.github/workflows/attestation-setup.yml' => 'packages/core/.github/workflows/attestation-setup.yml',
            '.github/verify-release-source.sh' => 'packages/core/.github/verify-release-source.sh',
            '.github/verify-release-artifacts.sh' => 'packages/core/.github/verify-release-artifacts.sh',
            '.github/canonicalize-release-zip.py' => 'packages/core/.github/canonicalize-release-zip.py',
        ],
        'axiomasoft/azguard-filament' => [
            '.github/workflows/provenance.yml' => 'packages/filament/.github/workflows/provenance.yml',
            '.github/workflows/attestation-setup.yml' => 'packages/filament/.github/workflows/attestation-setup.yml',
            '.github/verify-release-source.sh' => 'packages/filament/.github/verify-release-source.sh',
            '.github/verify-release-artifacts.sh' => 'packages/filament/.github/verify-release-artifacts.sh',
            '.github/canonicalize-release-zip.py' => 'packages/filament/.github/canonicalize-release-zip.py',
        ],
    ];
}

function releaseSetupProposal(string $root): array
{
    $payloads = [];
    foreach (releaseSetupPaths() as $repo => $paths) {
        foreach ($paths as $target => $source) {
            $hash = @hash_file('sha256', $root.'/'.$source);
            if (! is_string($hash)) {
                throw new RuntimeException('missing setup payload: '.$source);
            }
            $payloads[] = ['repository' => $repo, 'target' => $target, 'source' => $source, 'sha256' => $hash];
        }
    }
    $rulesets = [];
    foreach (['main.json', 'tags.json', 'tag-creation.json', 'required-checks.json'] as $file) {
        $path = '.github/rulesets/'.$file;
        $contents = @file_get_contents($root.'/'.$path);
        if (! is_string($contents)) {
            throw new RuntimeException('missing setup payload: '.$path);
        }
        $rulesets[] = ['path' => $path, 'sha256' => hash('sha256', $contents), 'body' => json_decode($contents, true, 512, JSON_THROW_ON_ERROR)];
    }

    return [
        'source_repository' => 'axiomasoft/azguard-private',
        'source_visibility' => 'No source visibility change is proposed. Probes bind the observed public or private visibility to the live setup verification.',
        'split_repositories' => ['axiomasoft/azguard', 'axiomasoft/azguard-filament'],
        'default_branch' => 'main',
        'split_visibility' => 'public',
        'distribution_policy' => [
            'guarantee' => 'The explicitly published release ZIP assets have checksums, validated SBOMs and verified split provenance.',
            'github_snapshots' => 'Provider-generated source ZIP/tar.gz snapshots are separate and their bytes are not claimed to have release-asset attestations.',
            'composer_delivery' => 'Packagist points Composer at the split repositories; the GitHub VCS driver can supply a GitHub-generated zipball, distinct from the signed release asset.',
            'references' => ['https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives', 'https://getcomposer.org/doc/05-repositories.md'],
        ],
        'environment' => ['repository' => 'axiomasoft/azguard-private', 'name' => 'release',
            'payload' => ['wait_timer' => 0, 'prevent_self_review' => false,
                'reviewers' => [['type' => 'User', 'id' => 3863273]], 'deployment_branch_policy' => null],
            'reviewer_login' => 'academici', 'basis' => '.github/CODEOWNERS; owner must review this proposed actor and permissions'],
        'variables' => ['RELEASE_TAG_PUBLIC_KEY', 'RELEASE_TAG_SIGNER_FINGERPRINT', 'SPLIT_ENABLED'],
        'secrets' => [
            ['name' => 'MONOREPO_SPLIT_TOKEN', 'configured_in' => 'axiomasoft/azguard-private', 'repositories' => ['axiomasoft/azguard', 'axiomasoft/azguard-filament'], 'permissions' => ['contents' => 'write', 'actions' => 'write']],
            ['name' => 'PRIVATE_RELEASE_READ_TOKEN', 'configured_in' => ['axiomasoft/azguard', 'axiomasoft/azguard-filament'], 'repositories' => ['axiomasoft/azguard-private'], 'permissions' => ['contents' => 'read', 'actions' => 'read']],
            ['name' => 'RELEASE_SETUP_READ_TOKEN', 'configured_in' => 'axiomasoft/azguard-private', 'repositories' => ['axiomasoft/azguard-private', 'axiomasoft/azguard', 'axiomasoft/azguard-filament'], 'permissions' => ['administration' => 'read', 'actions' => 'read', 'contents' => 'read', 'secrets' => 'read', 'variables' => 'read', 'security_events' => 'read', 'metadata' => 'read']],
        ],
        'payloads' => $payloads, 'rulesets_and_checks' => $rulesets,
        'setup_probe' => 'After explicit setup authorization, dispatch release-setup-probe.yml on main; it creates and deletes unique empty probe refs and dispatches both split attestation probes.',
    ];
}

/** A request is review material, not evidence of applied remote settings. */
function releaseSetupRequestErrors(array $request, string $root): array
{
    $errors = [];
    if (($request['schema'] ?? null) !== 'azguard.release.setup-request.v1' || ! array_key_exists('candidate_sha', $request) || $request['candidate_sha'] !== null
        || ($request['remote_changes_authorized'] ?? null) !== false
        || ($request['status'] ?? null) !== 'reviewable proposal; applied settings and entitlements are not proven') {
        $errors[] = 'invalid setup request identity';
    }
    if (($request['proposal'] ?? null) !== releaseSetupProposal($root)) {
        $errors[] = 'setup proposal or payload digest mismatch';
    }
    $observed = $request['observations'] ?? null;
    if (! is_array($observed) || ! is_string($observed['observed_at_utc'] ?? null)
        || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $observed['observed_at_utc'])
        || ! is_array($observed['repositories'] ?? null)) {
        $errors[] = 'missing observations';
    } else {
        foreach (array_keys(releaseSetupPaths()) as $repo) {
            $row = $observed['repositories'][$repo] ?? null;
            if (! is_array($row) || ! in_array($row['status'] ?? null, ['observed', 'unavailable'], true)
                || ! is_string($row['detail'] ?? null) || $row['detail'] === '') {
                $errors[] = 'missing observation for '.$repo;
            }
        }
    }

    return $errors;
}

/** Confirm the configured fingerprint belongs to the imported public key, including signing subkeys. */
function releaseSetupKeyMatches(string $key, string $fingerprint): bool
{
    $home = sys_get_temp_dir().'/azguard-setup-key-'.bin2hex(random_bytes(12));
    if (! mkdir($home, 0700)) {
        return false;
    }
    $env = getenv();
    $env['GNUPGHOME'] = $home;
    try {
        $process = proc_open(['gpg', '--batch', '--with-colons', '--import-options', 'show-only', '--dry-run', '--import'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (! is_resource($process)) {
            return false;
        }
        fwrite($pipes[0], $key);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || ! is_string($output)) {
            return false;
        }
        preg_match_all('/^fpr:::::::::([0-9A-F]+):/m', $output, $matches);

        return in_array($fingerprint, $matches[1] ?? [], true);
    } finally {
        $process = proc_open(['gpgconf', '--kill', 'gpg-agent'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (is_resource($process)) {
            proc_close($process);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($home);
    }
}

/** Run a read-only command with argv arrays, never print credentials or raw errors. */
function releaseSetupCommand(array $command): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process)) {
        return [127, ''];
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), is_string($output) ? $output : ''];
}

/** Parse complete GitHub paginated job evidence for one exact run and step. */
function releaseSetupProbeJobValid(array $pages, int $run, string $sha, string $jobName, array $steps): bool
{
    if ($pages === [] || ! array_is_list($pages)) {
        return false;
    }
    $jobs = [];
    foreach ($pages as $page) {
        if (! is_array($page) || ! is_int($page['total_count'] ?? null) || ! is_array($page['jobs'] ?? null) || ! array_is_list($page['jobs'])) {
            return false;
        }
        $jobs = [...$jobs, ...$page['jobs']];
    }
    foreach ($pages as $page) {
        if ($page['total_count'] !== count($jobs)) {
            return false;
        }
    }
    $ids = [];
    $matches = [];
    foreach ($jobs as $job) {
        if (! is_array($job) || ! is_int($job['id'] ?? null) || $job['id'] <= 0 || isset($ids[$job['id']])) {
            return false;
        }
        $ids[$job['id']] = true;
        if (($job['name'] ?? null) === $jobName) {
            $matches[] = $job;
        }
    }
    if (count($matches) !== 1) {
        return false;
    }
    $job = $matches[0];
    if (($job['run_id'] ?? null) !== $run || ($job['head_sha'] ?? null) !== $sha
        || ($job['status'] ?? null) !== 'completed' || ($job['conclusion'] ?? null) !== 'success'
        || ! is_array($job['steps'] ?? null)) {
        return false;
    }
    foreach ($steps as $stepName) {
        $found = array_values(array_filter($job['steps'], static fn (mixed $step): bool => is_array($step) && ($step['name'] ?? null) === $stepName));
        if (count($found) !== 1 || ($found[0]['status'] ?? null) !== 'completed' || ($found[0]['conclusion'] ?? null) !== 'success') {
            return false;
        }
    }

    return true;
}

/**
 * Verify real, recent GitHub state. The callbacks are replaceable only in local
 * fixtures; the CLI supplies read-only gh calls and cryptographic verification.
 */
function releaseSetupLiveErrors(array $request, string $root, callable $api, callable $verifyAttestation, callable $audit, int $now): array
{
    $errors = releaseSetupRequestErrors($request, $root);
    if ($errors !== []) {
        return $errors;
    }
    if (! $audit()) {
        $errors[] = 'source settings audit unavailable or failed';
    }
    $fingerprint = null;
    $publicKey = null;
    $heads = [];
    $sourcePrivate = null;
    foreach (releaseSetupPaths() as $repo => $paths) {
        $repoData = $api('repos/'.$repo);
        if (! is_array($repoData) || ($repoData['full_name'] ?? null) !== $repo || ($repoData['default_branch'] ?? null) !== 'main'
            || ($repoData['archived'] ?? null) !== false || ! is_bool($repoData['private'] ?? null)
            || ($repo !== 'axiomasoft/azguard-private' && $repoData['private'] !== false)) {
            $errors[] = 'repository identity, visibility or default branch unverified: '.$repo;
            continue;
        }
        if ($repo === 'axiomasoft/azguard-private') {
            $sourcePrivate = $repoData['private'];
        }
        $head = $api('repos/'.$repo.'/commits/main');
        $sha = is_array($head) ? ($head['sha'] ?? null) : null;
        if (! is_string($sha) || ! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
            $errors[] = 'default branch SHA unavailable: '.$repo;
            continue;
        }
        $heads[$repo] = $sha;
        foreach ($paths as $target => $source) {
            $file = $api('repos/'.$repo.'/contents/'.$target.'?ref='.$sha);
            $content = is_array($file) && ($file['encoding'] ?? null) === 'base64' && is_string($file['content'] ?? null)
                ? base64_decode(str_replace("\n", '', $file['content']), true) : false;
            if (! is_string($content) || ! hash_equals((string) hash_file('sha256', $root.'/'.$source), hash('sha256', $content))) {
                $errors[] = 'remote payload mismatch: '.$repo.'/'.$target;
            }
        }
        $fp = $api('repos/'.$repo.'/actions/variables/RELEASE_TAG_SIGNER_FINGERPRINT');
        $key = $api('repos/'.$repo.'/actions/variables/RELEASE_TAG_PUBLIC_KEY');
        $value = is_array($fp) ? ($fp['value'] ?? null) : null;
        $keyValue = is_array($key) ? ($key['value'] ?? null) : null;
        if (! is_string($value) || ! preg_match('/\A(?:[0-9A-F]{40}|[0-9A-F]{64})\z/', $value)
            || ! is_string($keyValue) || ! str_contains($keyValue, 'BEGIN PGP PUBLIC KEY BLOCK')
            || str_contains($keyValue, 'PRIVATE KEY BLOCK')) {
            $errors[] = 'tag verification identity unavailable: '.$repo;
        } elseif ($fingerprint !== null && ($fingerprint !== $value || $publicKey !== $keyValue)) {
            $errors[] = 'tag verification identities differ';
        } else {
            $fingerprint = $value;
            $publicKey = $keyValue;
        }
        if ($repo !== 'axiomasoft/azguard-private') {
            $secret = $api('repos/'.$repo.'/actions/secrets/PRIVATE_RELEASE_READ_TOKEN');
            if (! is_array($secret) || ($secret['name'] ?? null) !== 'PRIVATE_RELEASE_READ_TOKEN') {
                $errors[] = 'source read secret missing: '.$repo;
            }
        }
    }
    if ($fingerprint === null || $publicKey === null || ! releaseSetupKeyMatches($publicKey, $fingerprint)) {
        $errors[] = 'tag signing fingerprint does not belong to the configured key';
    }
    $environment = $api('repos/axiomasoft/azguard-private/environments/release');
    $reviewers = is_array($environment) ? ($environment['protection_rules'] ?? null) : null;
    $protected = false;
    $environmentExact = true;
    $reviewerRules = 0;
    if (is_array($reviewers)) {
        foreach ($reviewers as $rule) {
            if (is_array($rule) && ($rule['type'] ?? null) === 'required_reviewers'
                && is_array($rule['reviewers'] ?? null) && count($rule['reviewers']) === 1
                && ($rule['reviewers'][0]['type'] ?? null) === 'User'
                && ($rule['reviewers'][0]['reviewer']['id'] ?? null) === 3863273
                && ($rule['prevent_self_review'] ?? null) === false) {
                $protected = true;
                $reviewerRules++;
            } elseif (! is_array($rule) || ($rule['type'] ?? null) !== 'wait_timer' || ($rule['wait_timer'] ?? null) !== 0) {
                $environmentExact = false;
            }
        }
    }
    if (! is_array($environment) || ($environment['name'] ?? null) !== 'release' || ! $protected || $reviewerRules !== 1 || ! $environmentExact
        || ! array_key_exists('deployment_branch_policy', $environment) || $environment['deployment_branch_policy'] !== null) {
        $errors[] = 'release environment reviewers unverified';
    }
    $secret = $api('repos/axiomasoft/azguard-private/actions/secrets/MONOREPO_SPLIT_TOKEN');
    if (! is_array($secret) || ($secret['name'] ?? null) !== 'MONOREPO_SPLIT_TOKEN') {
        $errors[] = 'split token metadata unavailable';
    }
    $readSecret = $api('repos/axiomasoft/azguard-private/actions/secrets/RELEASE_SETUP_READ_TOKEN');
    if (! is_array($readSecret) || ($readSecret['name'] ?? null) !== 'RELEASE_SETUP_READ_TOKEN') {
        $errors[] = 'setup read token metadata unavailable';
    }
    $enabled = $api('repos/axiomasoft/azguard-private/actions/variables/SPLIT_ENABLED');
    if (! is_array($enabled) || ($enabled['name'] ?? null) !== 'SPLIT_ENABLED' || ($enabled['value'] ?? null) !== 'true') {
        $errors[] = 'split variable not enabled';
    }
    foreach ($heads as $repo => $sha) {
        $source = $repo === 'axiomasoft/azguard-private';
        $workflow = $source ? 'release-setup-probe.yml' : 'attestation-setup.yml';
        $workflowData = $api('repos/'.$repo.'/actions/workflows/'.$workflow);
        $runs = $api('repos/'.$repo.'/actions/workflows/'.$workflow.'/runs?branch=main&event=workflow_dispatch&status=completed&per_page=100');
        $workflowId = is_array($workflowData) ? ($workflowData['id'] ?? null) : null;
        if (! is_int($workflowId) || ($workflowData['state'] ?? null) !== 'active'
            || ($workflowData['path'] ?? null) !== '.github/workflows/'.$workflow || ! is_array($runs['workflow_runs'] ?? null)) {
            $errors[] = 'setup probe unavailable: '.$repo;
            continue;
        }
        $validRun = false;
        foreach ($runs['workflow_runs'] as $run) {
            if (! is_array($run)) {
                continue;
            }
            $created = is_string($run['created_at'] ?? null) ? strtotime($run['created_at']) : false;
            if (! is_int($run['id'] ?? null) || ! is_int($run['run_attempt'] ?? null) || $run['run_attempt'] < 1
                || ($run['workflow_id'] ?? null) !== $workflowId || ($run['head_sha'] ?? null) !== $sha
                || ($run['repository']['full_name'] ?? null) !== $repo
                || ($run['head_branch'] ?? null) !== 'main' || ($run['event'] ?? null) !== 'workflow_dispatch'
                || ($run['status'] ?? null) !== 'completed' || ($run['conclusion'] ?? null) !== 'success'
                || $created === false || $created < $now - 3600 || $created > $now + 300) {
                continue;
            }
            $pages = $api('repos/'.$repo.'/actions/runs/'.$run['id'].'/attempts/'.$run['run_attempt'].'/jobs?per_page=100', true);
            $jobName = $source ? 'Verify split publication prerequisites' : 'Verify split attestation prerequisites';
            $steps = $source ? ['Verify setup read token', 'Probe split push and dispatch permissions'] : ['Verify source read access', 'Create setup probe', 'Attest setup probe'];
            if (! $source) {
                if ($sourcePrivate === null) {
                    continue;
                }
                $steps[] = $sourcePrivate ? 'Confirm private source' : 'Confirm public source';
            }
            if (is_array($pages) && releaseSetupProbeJobValid($pages, $run['id'], $sha, $jobName, $steps)) {
                $validRun = true;
                break;
            }
        }
        if (! $validRun) {
            $errors[] = 'fresh successful setup probe unavailable: '.$repo;
        } elseif (! $source && ! $verifyAttestation($repo, $sha)) {
            $errors[] = 'public attestation entitlement unverified: '.$repo;
        }
    }

    return $errors;
}
