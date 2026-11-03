<?php

declare(strict_types=1);

/**
 * Produce a deterministic inventory of the actual files in a package ZIP.
 * This is only one input to release evidence: it does not establish schema
 * validation, dependency resolution, source mapping or an attestation.
 *
 * @return array<string, mixed>
 */
function packageSourceInventory(string $archive, string $package, string $version): array
{
    if (! preg_match('/\Aaxiomasoft\/(?:azguard|azguard-filament)\z/', $package)
        || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $version)) {
        throw new RuntimeException('invalid package identity or version');
    }
    $zip = new ZipArchive;

    if ($zip->open($archive) !== true) {
        throw new RuntimeException('cannot open ZIP');
    }
    $files = [];
    $seen = [];
    $manifest = null;

    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! is_string($name) || $name === '' || str_contains($name, "\0") || str_contains($name, '\\')
                || str_starts_with($name, '/') || preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)|//~', $name)) {
                throw new RuntimeException('unsafe ZIP path');
            }

            if (isset($seen[$name])) {
                throw new RuntimeException('duplicate ZIP member');
            }
            $seen[$name] = true;

            if (! $zip->getExternalAttributesIndex($i, $os, $attributes)) {
                throw new RuntimeException('cannot read ZIP attributes');
            }
            $mode = ($attributes >> 16) & 0170000;

            if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) {
                throw new RuntimeException('non-regular ZIP member');
            }

            if (str_ends_with($name, '/')) {
                if ($mode !== 0 && $mode !== 0040000) {
                    throw new RuntimeException('invalid ZIP directory');
                }

                continue;
            }

            if ($mode === 0040000) {
                throw new RuntimeException('invalid ZIP file');
            }
            $contents = $zip->getFromIndex($i);

            if (! is_string($contents)) {
                throw new RuntimeException('cannot read ZIP member');
            }

            if ($name === 'composer.json') {
                $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            }
            $files[$name] = hash('sha256', $contents);
        }
    } finally {
        $zip->close();
    }

    if ($files === []) {
        throw new RuntimeException('empty ZIP');
    }

    if (! is_array($manifest) || ($manifest['name'] ?? null) !== $package || ($manifest['version'] ?? null) !== $version) {
        throw new RuntimeException('ZIP composer identity does not match');
    }
    ksort($files, SORT_STRING);
    $components = [];
    foreach ($files as $name => $digest) {
        $components[] = ['type' => 'file', 'name' => $name, 'hashes' => [['alg' => 'SHA-256', 'content' => $digest]]];
    }

    return [
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.5',
        'version' => 1,
        'metadata' => ['component' => ['type' => 'library', 'name' => $package, 'version' => $version, 'purl' => 'pkg:composer/'.$package.'@'.$version]],
        'components' => $components,
    ];
}

/** Check that the resolved SBOM enumerates exactly the Composer lock graph. */
function packageResolvedGraphErrors(array $lock, array $bom, string $subject, string $version): array
{
    $errors = [];
    $locked = [];
    $seenSubject = false;

    if (! is_array($lock['packages'] ?? null) || ! array_is_list($lock['packages'])) {
        return ['invalid resolved lock'];
    }
    foreach ($lock['packages'] as $row) {
        if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['version'] ?? null)
            || ! preg_match('~\A[a-z0-9_.-]+/[a-z0-9_.-]+\z~', $row['name']) || $row['version'] === '') {
            $errors[] = 'invalid locked package';

            continue;
        }
        $name = $row['name'];

        if ($name === $subject) {
            if ($seenSubject || $row['version'] !== $version) {
                $errors[] = 'locked subject identity mismatch';
            }
            $seenSubject = true;

            continue;
        }

        if (isset($locked[$name])) {
            $errors[] = 'duplicate locked package';
        }
        $locked[$name] = $row['version'];
    }

    if (! $seenSubject || ($bom['metadata']['component']['name'] ?? null) !== $subject
        || ($bom['metadata']['component']['version'] ?? null) !== $version) {
        $errors[] = 'resolved subject mismatch';
    }
    $components = $bom['components'] ?? null;

    if (! is_array($components) || ! array_is_list($components)) {
        return [...$errors, 'invalid resolved components'];
    }
    $found = [];
    foreach ($components as $component) {
        $purl = $component['purl'] ?? null;

        if (! is_array($component) || ! is_string($purl)
            || preg_match('~\Apkg:composer/([^@?#]+)@([^?#]+)(?:\?[^#]*)?(?:#.*)?\z~', $purl, $matches) !== 1) {
            $errors[] = 'invalid resolved component';

            continue;
        }
        $name = rawurldecode($matches[1]);
        $resolved = rawurldecode($matches[2]);

        if (($component['version'] ?? null) !== $resolved || isset($found[$name])) {
            $errors[] = 'duplicate or inconsistent resolved component';
        }
        $found[$name] = $resolved;
    }
    ksort($locked, SORT_STRING);
    ksort($found, SORT_STRING);

    if ($found !== $locked) {
        $errors[] = 'SBOM does not match resolved lock graph';
    }

    return $errors;
}

/** Execute a fixed argv vector without a shell. */
function packageEvidenceCommand(array $arguments): string
{
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('cannot start required command');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    if (proc_close($process) !== 0 || ! is_string($output)) {
        throw new RuntimeException('required command failed: '.basename($arguments[0]));
    }

    return $output;
}

/** Hash the complete copied worktree input without following symlinks. */
function packageWorktreeDigest(string $directory): string
{
    $root = realpath($directory);

    if ($root === false || ! is_dir($root) || is_link($directory)) {
        throw new RuntimeException('invalid worktree package directory');
    }
    $entries = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $name = substr($path, strlen($root) + 1);

        if ($entry->isLink()) {
            $target = readlink($path);

            if (! is_string($target)) {
                throw new RuntimeException('cannot read worktree symlink');
            }
            $entries[$name] = ['type' => 'symlink', 'target' => $target];
        } elseif ($entry->isDir()) {
            $entries[$name] = ['type' => 'directory'];
        } elseif ($entry->isFile()) {
            $digest = hash_file('sha256', $path);

            if (! is_string($digest)) {
                throw new RuntimeException('cannot hash worktree file');
            }
            $entries[$name] = ['type' => 'file', 'executable' => ($entry->getPerms() & 0111) !== 0, 'sha256' => $digest];
        } else {
            throw new RuntimeException('unsupported worktree entry');
        }
    }
    ksort($entries, SORT_STRING);

    return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Remove only a temporary directory created by the offline fixture. */
function packageRemoveFixtureDirectory(string $directory): void
{
    if (is_link($directory) || is_file($directory)) {
        unlink($directory);

        return;
    }

    if (! is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

/** Build an explicitly synthetic offline rehearsal; it never makes a release assertion. */
function packageOfflineFixture(string $root, string $version, string $out): void
{
    if (! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $version) || $out === '') {
        throw new RuntimeException('invalid offline fixture version or output directory');
    }
    $root = realpath($root) ?: throw new RuntimeException('missing repository root');
    $toolsLock = json_decode((string) file_get_contents($root.'/.github/security-tools.lock'), true, 512, JSON_THROW_ON_ERROR);
    $tool = $toolsLock['tools']['cyclonedx-cli'] ?? null;

    if (! is_array($tool) || ($tool['kind'] ?? null) !== 'binary' || ($tool['bin'] ?? null) !== 'cyclonedx-cli'
        || ! is_string($tool['version'] ?? null) || ! preg_match('/\A[0-9.]+\z/', $tool['version'])
        || ! is_string($tool['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $tool['sha256'])) {
        throw new RuntimeException('invalid pinned CycloneDX validator');
    }
    $toolRoot = getenv('AZGUARD_TOOLS_DIR') ?: $root.'/var/tools';
    $validator = $toolRoot.'/cyclonedx-cli-'.$tool['version'].'/cyclonedx-cli';

    if (! is_executable($validator) || ! hash_equals($tool['sha256'], (string) hash_file('sha256', $validator))) {
        throw new RuntimeException('offline fixture requires the cached, hash-verified CycloneDX validator');
    }

    if (is_link($out) || (file_exists($out) && ! is_dir($out))) {
        throw new RuntimeException('invalid offline fixture output directory');
    }

    if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
        throw new RuntimeException('cannot create offline fixture output');
    }
    $existing = new FilesystemIterator($out, FilesystemIterator::SKIP_DOTS);

    if ($existing->valid()) {
        throw new RuntimeException('offline fixture output directory must be empty');
    }
    $work = sys_get_temp_dir().'/azguard-package-offline-'.bin2hex(random_bytes(12));

    if (! mkdir($work, 0700)) {
        throw new RuntimeException('cannot create offline fixture workspace');
    }

    if (! mkdir($work.'/result')) {
        rmdir($work);

        throw new RuntimeException('cannot create offline fixture workspace');
    }
    $previousNetwork = getenv('COMPOSER_DISABLE_NETWORK');
    putenv('COMPOSER_DISABLE_NETWORK=1');
    $published = [];

    try {
        $base = trim(packageEvidenceCommand(['git', '-C', $root, 'rev-parse', '--verify', 'HEAD^{commit}']));

        if (! preg_match('/\A[0-9a-f]{40}\z/', $base)) {
            throw new RuntimeException('invalid worktree base commit');
        }
        $mappings = [];
        $files = [];
        foreach (['core' => 'axiomasoft/azguard'] as $package => $name) {
            $source = $root.'/packages/'.$package;
            $sourceDigest = packageWorktreeDigest($source);
            $snapshot = $work.'/snapshot-'.$package;
            packageEvidenceCommand(['cp', '-a', $source, $snapshot]);

            if ($sourceDigest !== packageWorktreeDigest($snapshot) || $sourceDigest !== packageWorktreeDigest($source)) {
                throw new RuntimeException('worktree changed during fixture snapshot');
            }
            $archives = [];
            $before = (string) file_get_contents($snapshot.'/composer.json');
            $composer = json_decode($before, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($composer) || ($composer['name'] ?? null) !== $name) {
                throw new RuntimeException('worktree package identity mismatch');
            }
            $composer['version'] = $version;
            $after = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            foreach ([0, 1] as $index) {
                $copy = $work.'/package-'.$package.'-'.$index;
                $built = $work.'/built-'.$package.'-'.$index;
                packageEvidenceCommand(['cp', '-a', $snapshot, $copy]);
                // Match the consumer fixture: split-workflow files are never shipped.
                packageRemoveFixtureDirectory($copy.'/.github');

                if (! mkdir($built) || file_put_contents($copy.'/composer.json', $after) !== strlen($after)) {
                    throw new RuntimeException('cannot prepare fixture package');
                }
                packageEvidenceCommand(['composer', 'archive', '--format=zip', '--dir='.$built, '--working-dir='.$copy, '--no-interaction']);
                $candidates = glob($built.'/*.zip') ?: [];

                if (count($candidates) !== 1) {
                    throw new RuntimeException('fixture requires exactly one ZIP per build');
                }
                $archives[] = $candidates[0];
            }
            $inventory = packageSourceInventory($archives[0], $name, $version);

            if ($inventory !== packageSourceInventory($archives[1], $name, $version)
                || $sourceDigest !== packageWorktreeDigest($snapshot) || $sourceDigest !== packageWorktreeDigest($source)) {
                throw new RuntimeException('fixture rebuild or worktree snapshot mismatch');
            }
            $zip = new ZipArchive;

            if ($zip->open($archives[0]) !== true) {
                throw new RuntimeException('cannot inspect fixture ZIP');
            }

            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    if (! is_string($entry) || preg_match('~(^|/)(vendor|node_modules|tests?|\.git|\.github|\.agents|build|bench|plan|evidence)(/|$)|(^|/)(\.env[^/]*|[^/]*\.(pem|key))$~i', $entry)) {
                        throw new RuntimeException('forbidden fixture ZIP entry: '.(is_string($entry) ? $entry : 'invalid'));
                    }
                }
            } finally {
                $zip->close();
            }
            $zipName = 'azguard-'.$package.'-'.$version.'.zip';

            if (! copy($archives[0], $work.'/result/'.$zipName)) {
                throw new RuntimeException('cannot copy fixture ZIP');
            }
            $sourceName = 'source-'.$package.'.cdx.json';
            $resolvedName = 'sbom-'.$package.'.cdx.json';
            $lockName = 'resolution-'.$package.'.lock';
            $resolved = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
                'metadata' => ['component' => ['type' => 'library', 'name' => $name, 'version' => $version],
                    'properties' => [['name' => 'azguard:fixture', 'value' => 'synthetic graph; not a resolved consumer or release SBOM']]],
                'components' => []];
            $lock = ['packages' => [['name' => $name, 'version' => $version]], 'packages-dev' => [],
                'fixture' => 'synthetic; no consumer dependency resolution performed'];

            if (packageResolvedGraphErrors($lock, $resolved, $name, $version) !== []) {
                throw new RuntimeException('invalid synthetic fixture graph');
            }
            foreach ([$sourceName => $inventory, $resolvedName => $resolved, $lockName => $lock] as $file => $data) {
                $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

                if (file_put_contents($work.'/result/'.$file, $encoded) !== strlen($encoded)) {
                    throw new RuntimeException('cannot write fixture evidence');
                }
            }
            foreach ([$sourceName, $resolvedName] as $file) {
                packageEvidenceCommand([$validator, 'validate', '--input-file', $work.'/result/'.$file,
                    '--input-format', 'json', '--input-version', 'v1_5', '--fail-on-errors']);
            }
            $mappings[$package] = ['package_name' => $name, 'source_worktree_sha256' => $sourceDigest,
                'export_exclusions' => ['.github'],
                'composer_transform' => ['operation' => 'set-version', 'value' => $version,
                    'before_sha256' => hash('sha256', $before), 'after_sha256' => hash('sha256', $after)],
                'zip' => $zipName, 'zip_sha256' => hash_file('sha256', $work.'/result/'.$zipName),
                'source_sbom' => $sourceName, 'source_sbom_sha256' => hash_file('sha256', $work.'/result/'.$sourceName),
                'resolved_sbom' => $resolvedName, 'resolved_sbom_sha256' => hash_file('sha256', $work.'/result/'.$resolvedName),
                'resolution_lock' => $lockName, 'resolution_lock_sha256' => hash_file('sha256', $work.'/result/'.$lockName),
                'rebuild_matches' => true, 'split_commit' => null];
            array_push($files, $zipName, $sourceName, $resolvedName, $lockName);
        }
        $evidence = ['schema' => 'azguard.package-evidence.offline-fixture.v2', 'qualification' => 'nonrelease',
            'source' => 'worktree', 'base_commit' => $base, 'version' => $version,
            'consumer_graph' => 'synthetic-offline-fixture; no consumer resolution or advisory audit performed',
            'attestations' => [], 'split_mapping' => 'not performed', 'packages' => $mappings];
        $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($work.'/result/evidence.json', $encoded) !== strlen($encoded)) {
            throw new RuntimeException('cannot write fixture mapping');
        }
        $files[] = 'evidence.json';
        $checksums = '';
        foreach ($files as $file) {
            $checksums .= hash_file('sha256', $work.'/result/'.$file).'  '.$file."\n";
        }

        if (file_put_contents($work.'/result/SHA256SUMS', $checksums) !== strlen($checksums)) {
            throw new RuntimeException('cannot write fixture checksums');
        }
        foreach ([...$files, 'SHA256SUMS'] as $file) {
            $target = fopen($out.'/'.$file, 'x');

            if ($target === false) {
                throw new RuntimeException('cannot create fixture output');
            }
            $published[] = $out.'/'.$file;

            try {
                $input = fopen($work.'/result/'.$file, 'rb');

                if ($input === false) {
                    throw new RuntimeException('cannot read staged fixture output');
                }

                try {
                    if (stream_copy_to_stream($input, $target) !== filesize($work.'/result/'.$file)) {
                        throw new RuntimeException('cannot write complete fixture output');
                    }
                } finally {
                    fclose($input);
                }
            } finally {
                fclose($target);
            }

            if (! hash_equals((string) hash_file('sha256', $work.'/result/'.$file), (string) hash_file('sha256', $out.'/'.$file))) {
                throw new RuntimeException('fixture output digest mismatch');
            }
        }
    } catch (Throwable $error) {
        foreach ($published as $path) {
            @unlink($path);
        }

        throw $error;
    } finally {
        $previousNetwork === false ? putenv('COMPOSER_DISABLE_NETWORK') : putenv('COMPOSER_DISABLE_NETWORK='.$previousNetwork);
        packageRemoveFixtureDirectory($work);
    }
}

/**
 * Describe the exact candidate bytes. The split tag is an expected target until
 * publication independently proves the remote tag; this record is not an attestation.
 *
 * @return array<string, mixed>
 */
function packageBuildManifest(string $root, string $package, string $version, string $dist, ?array $workflow): array
{
    if (! in_array($package, ['core', 'filament'], true)
        || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $version) !== 1) {
        throw new RuntimeException('invalid package or version');
    }
    $root = realpath($root) ?: throw new RuntimeException('missing source root');
    $dist = realpath($dist) ?: throw new RuntimeException('missing artifact directory');
    $git = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $root, ...$args]));
    $sha = $git('rev-parse', '--verify', 'HEAD^{commit}');

    if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
        throw new RuntimeException('invalid source commit');
    }

    // Release workflow input must be clean. Local rehearsals are rebuilt and
    // checked against committed HEAD, regardless of uncommitted files.
    if ($workflow !== null) {
        packageEvidenceCommand(['git', '-C', $root, 'diff', '--quiet', $sha, '--', 'packages/'.$package]);

        if ($git('ls-files', '--others', '--exclude-standard', '--', 'packages/'.$package) !== ''
            || $git('ls-files', '--others', '--ignored', '--exclude-standard', '--', 'packages/'.$package) !== '') {
            throw new RuntimeException('untracked package input');
        }
    }
    $tree = $git('rev-parse', '--verify', $sha.':packages/'.$package);
    $split = $git('subtree', 'split', '--prefix=packages/'.$package, $sha);

    if (! preg_match('/\A[0-9a-f]{40}\z/', $tree) || ! preg_match('/\A[0-9a-f]{40}\z/', $split)
        || $git('rev-parse', '--verify', $split.'^{tree}') !== $tree) {
        throw new RuntimeException('split tree mismatch');
    }
    $name = $package === 'core' ? 'axiomasoft/azguard' : 'axiomasoft/azguard-filament';
    $files = [
        'zip' => 'azguard-'.$package.'-'.$version.'.zip',
        'source_sbom' => 'source-'.$package.'.cdx.json',
        'resolved_sbom' => 'sbom-'.$package.'.cdx.json',
        'resolution_lock' => 'resolution-'.$package.'.lock',
    ];
    $digests = [];
    foreach ($files as $kind => $file) {
        $hash = @hash_file('sha256', $dist.'/'.$file);

        if (! is_string($hash)) {
            throw new RuntimeException('missing artifact: '.$file);
        }
        $digests[$kind] = ['file' => $file, 'sha256' => $hash];
    }
    $before = packageEvidenceCommand(['git', '-C', $root, 'show', $sha.':packages/'.$package.'/composer.json']);
    $composer = json_decode($before, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($composer) || ($composer['name'] ?? null) !== $name) {
        throw new RuntimeException('source composer identity mismatch');
    }
    $composer['version'] = $version;
    $after = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    $zip = new ZipArchive;

    if ($zip->open($dist.'/'.$files['zip']) !== true) {
        throw new RuntimeException('cannot open package ZIP');
    }
    $actualManifest = $zip->getFromName('composer.json');
    $zip->close();

    if ($actualManifest !== $after) {
        throw new RuntimeException('ZIP composer transform mismatch');
    }
    $source = json_decode((string) file_get_contents($dist.'/'.$files['source_sbom']), true, 512, JSON_THROW_ON_ERROR);
    $resolved = json_decode((string) file_get_contents($dist.'/'.$files['resolved_sbom']), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents($dist.'/'.$files['resolution_lock']), true, 512, JSON_THROW_ON_ERROR);

    if ($source !== packageSourceInventory($dist.'/'.$files['zip'], $name, $version)
        || ! is_array($resolved) || ! is_array($lock) || packageResolvedGraphErrors($lock, $resolved, $name, $version) !== []) {
        throw new RuntimeException('SBOM or resolved graph mismatch');
    }
    packageCompareSplitArchive($root, $split, $name, $version, $source);
    $workflowData = null;
    $references = [];

    if ($workflow !== null) {
        if (($workflow['repository'] ?? null) !== 'axiomasoft/azguard-private'
            || ($workflow['source_sha'] ?? null) !== $sha
            || ($workflow['tag'] ?? null) !== 'v'.$version
            || ($workflow['workflow_sha'] ?? null) !== $sha
            || ! is_string($workflow['run_id'] ?? null) || ! preg_match('/\A[1-9][0-9]*\z/', $workflow['run_id'])
            || ! is_string($workflow['run_attempt'] ?? null) || ! preg_match('/\A[1-9][0-9]*\z/', $workflow['run_attempt'])) {
            throw new RuntimeException('invalid workflow identity');
        }
        $workflowData = $workflow;
        $references[] = 'https://github.com/axiomasoft/azguard-private/actions/runs/'.$workflow['run_id'].'/attempts/'.$workflow['run_attempt'];
    }

    return [
        'schema' => 'azguard.package-evidence.v1',
        'qualification' => $workflow === null ? 'nonrelease' : 'pending',
        'monorepo_sha' => $sha, 'package' => $package, 'package_name' => $name,
        'exported_tree_git_sha1' => $tree,
        'transform' => ['path' => 'composer.json', 'operation' => 'set-version', 'value' => $version,
            'before_sha256' => hash('sha256', $before), 'after_sha256' => hash('sha256', $after)],
        'split_commit' => $split, 'split_tag' => 'v'.$version,
        'split_tag_peeled_sha' => $split, 'split_tag_state' => 'expected',
        'artifacts' => $digests, 'workflow' => $workflowData, 'qualification_references' => $references,
    ];
}

/** Compare all ZIP file bytes to a fresh Composer archive of the Git split. */
function packageCompareSplitArchive(string $repository, string $split, string $name, string $version, array $inventory): void
{
    $work = sys_get_temp_dir().'/azguard-package-rebuild-'.bin2hex(random_bytes(12));

    if (! mkdir($work, 0700) || ! mkdir($work.'/package') || ! mkdir($work.'/built')) {
        throw new RuntimeException('cannot create temporary build');
    }

    try {
        $tree = packageEvidenceCommand(['git', '-C', $repository, 'ls-tree', '-r', '-z', $split]);
        foreach (explode("\0", rtrim($tree, "\0")) as $entry) {
            if (! preg_match('/\A100(?:644|755) blob [0-9a-f]{40}\t/s', $entry)) {
                throw new RuntimeException('split tree contains a non-regular file');
            }
        }
        $tar = packageEvidenceCommand(['git', '-C', $repository, 'archive', '--format=tar', $split]);

        if (file_put_contents($work.'/split.tar', $tar) !== strlen($tar)) {
            throw new RuntimeException('cannot write temporary archive');
        }
        packageEvidenceCommand(['tar', '-xf', $work.'/split.tar', '-C', $work.'/package']);
        $path = $work.'/package/composer.json';
        $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($composer) || ($composer['name'] ?? null) !== $name) {
            throw new RuntimeException('split composer identity mismatch');
        }
        $composer['version'] = $version;
        $after = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($path, $after) !== strlen($after)) {
            throw new RuntimeException('cannot apply version transform');
        }
        packageEvidenceCommand(['composer', 'archive', '--format=zip', '--dir='.$work.'/built', '--working-dir='.$work.'/package', '--no-interaction']);
        $built = glob($work.'/built/*.zip') ?: [];

        if (count($built) !== 1 || packageSourceInventory($built[0], $name, $version) !== $inventory) {
            throw new RuntimeException('ZIP does not match transformed split tree');
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($work);
    }
}

/** Verify a pending build manifest against the checked-out, already authenticated source and split. */
function packageVerifyProducerManifest(string $mono, string $split, string $dist, string $package, string $tag, string $runId, string $attempt, ?string $expectedSplit = null): void
{
    if (! in_array($package, ['core', 'filament'], true) || ! preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $tag)
        || ! preg_match('/\A[1-9][0-9]*\z/', $runId) || ! preg_match('/\A[1-9][0-9]*\z/', $attempt)) {
        throw new RuntimeException('invalid producer identity');
    }
    $mono = realpath($mono) ?: throw new RuntimeException('missing authenticated source');
    $split = realpath($split) ?: throw new RuntimeException('missing split');
    $dist = realpath($dist) ?: throw new RuntimeException('missing artifacts');
    $version = substr($tag, 1);
    $name = $package === 'core' ? 'axiomasoft/azguard' : 'axiomasoft/azguard-filament';
    $gitMono = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $mono, ...$args]));
    $gitSplit = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $split, ...$args]));
    $sha = $gitMono('rev-parse', '--verify', 'HEAD^{commit}');

    if ($expectedSplit !== null && preg_match('/\A[0-9a-f]{40}\z/', $expectedSplit) !== 1) {
        throw new RuntimeException('invalid expected split commit');
    }
    $splitSha = $gitSplit('rev-parse', '--verify', ($expectedSplit ?? 'HEAD').'^{commit}');
    $tree = $gitMono('rev-parse', '--verify', $sha.':packages/'.$package);

    if ($tree !== $gitSplit('rev-parse', '--verify', $splitSha.'^{tree}')) {
        throw new RuntimeException('split tree mismatch');
    }
    $files = ['zip' => 'azguard-'.$package.'-'.$version.'.zip', 'source_sbom' => 'source-'.$package.'.cdx.json',
        'resolved_sbom' => 'sbom-'.$package.'.cdx.json', 'resolution_lock' => 'resolution-'.$package.'.lock'];
    $digests = [];
    foreach ($files as $kind => $file) {
        $digest = @hash_file('sha256', $dist.'/'.$file);

        if (! is_string($digest)) {
            throw new RuntimeException('missing artifact: '.$file);
        }
        $digests[$kind] = ['file' => $file, 'sha256' => $digest];
    }
    $before = packageEvidenceCommand(['git', '-C', $mono, 'show', $sha.':packages/'.$package.'/composer.json']);
    $composer = json_decode($before, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($composer) || ($composer['name'] ?? null) !== $name) {
        throw new RuntimeException('invalid source composer identity');
    }
    $composer['version'] = $version;
    $after = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    $expected = ['schema' => 'azguard.package-evidence.v1', 'qualification' => 'pending', 'monorepo_sha' => $sha,
        'package' => $package, 'package_name' => $name, 'exported_tree_git_sha1' => $tree,
        'transform' => ['path' => 'composer.json', 'operation' => 'set-version', 'value' => $version,
            'before_sha256' => hash('sha256', $before), 'after_sha256' => hash('sha256', $after)],
        'split_commit' => $splitSha, 'split_tag' => $tag, 'split_tag_peeled_sha' => $splitSha, 'split_tag_state' => 'expected',
        'artifacts' => $digests,
        'workflow' => ['repository' => 'axiomasoft/azguard-private', 'source_sha' => $sha, 'tag' => $tag,
            'workflow_sha' => $sha, 'run_id' => $runId, 'run_attempt' => $attempt],
        'qualification_references' => ['https://github.com/axiomasoft/azguard-private/actions/runs/'.$runId.'/attempts/'.$attempt]];
    $actual = json_decode((string) file_get_contents($dist.'/mapping-'.$package.'.json'), true, 512, JSON_THROW_ON_ERROR);

    if ($actual !== $expected) {
        throw new RuntimeException('package manifest does not match authenticated inputs');
    }
    $source = json_decode((string) file_get_contents($dist.'/'.$files['source_sbom']), true, 512, JSON_THROW_ON_ERROR);
    $resolved = json_decode((string) file_get_contents($dist.'/'.$files['resolved_sbom']), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents($dist.'/'.$files['resolution_lock']), true, 512, JSON_THROW_ON_ERROR);

    if ($source !== packageSourceInventory($dist.'/'.$files['zip'], $name, $version)
        || ! is_array($resolved) || ! is_array($lock) || packageResolvedGraphErrors($lock, $resolved, $name, $version) !== []) {
        throw new RuntimeException('SBOM or lock mismatch');
    }
    packageCompareSplitArchive($split, $splitSha, $name, $version, $source);
}

/**
 * Independently rebuild an archive from the tagged split tree and compare the
 * members. The result is explicitly a local rehearsal, not a signature or a
 * release qualification. The caller must verify the tag signature separately.
 *
 * @return array<string, mixed>
 */
function packageLocalMapping(string $mono, string $split, string $package, string $tag, string $version, string $archive, string $sourceSbom): array
{
    if (! in_array($package, ['core', 'filament'], true)
        || ! preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $tag)
        || $tag !== 'v'.$version) {
        throw new RuntimeException('invalid package, tag or version');
    }
    $mono = realpath($mono) ?: throw new RuntimeException('missing monorepo');
    $split = realpath($split) ?: throw new RuntimeException('missing split repository');
    $archive = realpath($archive) ?: throw new RuntimeException('missing ZIP');
    $sourceSbom = realpath($sourceSbom) ?: throw new RuntimeException('missing source SBOM');
    $gitMono = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $mono, ...$args]));
    $gitSplit = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $split, ...$args]));
    $monoSha = $gitMono('rev-parse', '--verify', 'refs/tags/'.$tag.'^{commit}');
    $splitSha = $gitSplit('rev-parse', '--verify', 'refs/tags/'.$tag.'^{commit}');
    $monoTree = $gitMono('rev-parse', '--verify', $monoSha.':packages/'.$package);
    $splitTree = $gitSplit('rev-parse', '--verify', $splitSha.'^{tree}');
    foreach ([$monoSha, $splitSha, $monoTree, $splitTree] as $sha) {
        if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
            throw new RuntimeException('invalid Git object identity');
        }
    }

    if (! hash_equals($monoTree, $splitTree)) {
        throw new RuntimeException('split tree does not match monorepo');
    }
    $name = $package === 'core' ? 'axiomasoft/azguard' : 'axiomasoft/azguard-filament';
    $actualInventory = packageSourceInventory($archive, $name, $version);
    $bom = json_decode((string) file_get_contents($sourceSbom), true, 512, JSON_THROW_ON_ERROR);

    if ($bom !== $actualInventory) {
        throw new RuntimeException('source SBOM does not match ZIP');
    }
    packageCompareSplitArchive($split, $splitSha, $name, $version, $actualInventory);
    $before = packageEvidenceCommand(['git', '-C', $split, 'show', $splitSha.':composer.json']);
    $manifest = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
    $manifest['version'] = $version;
    $after = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

    return [
        'schema' => 'azguard.package-mapping.local.v1',
        'qualification' => 'nonrelease',
        'monorepo_sha' => $monoSha,
        'package' => $package,
        'exported_tree_git_sha1' => $monoTree,
        'transform' => ['path' => 'composer.json', 'operation' => 'set-version', 'value' => $version,
            'before_sha256' => hash('sha256', $before), 'after_sha256' => hash('sha256', $after)],
        'split_commit' => $splitSha,
        'split_tag' => $tag,
        'split_tag_peeled_sha' => $splitSha,
        'zip_sha256' => hash_file('sha256', $archive),
        'source_sbom_sha256' => hash_file('sha256', $sourceSbom),
        'qualification_references' => [],
        'workflow_sha' => null, 'run_id' => null, 'run_attempt' => null,
    ];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if ($argc > 1 && (str_starts_with($argv[1], '--source=') || in_array('--offline-fixture', $argv, true))) {
            $options = [];
            foreach (array_slice($argv, 1) as $argument) {
                if ($argument === '--offline-fixture') {
                    $key = 'offline-fixture';
                    $value = true;
                } elseif (preg_match('/\A--(source|version|out)=(.+)\z/s', $argument, $match)) {
                    $key = $match[1];
                    $value = $match[2];
                } else {
                    throw new RuntimeException('invalid offline fixture option');
                }

                if (array_key_exists($key, $options)) {
                    throw new RuntimeException('duplicate offline fixture option');
                }
                $options[$key] = $value;
            }

            if (count($options) !== 4 || ($options['source'] ?? null) !== 'worktree'
                || ($options['offline-fixture'] ?? null) !== true
                || ! is_string($options['version'] ?? null) || ! is_string($options['out'] ?? null)) {
                throw new RuntimeException('offline fixture requires --source=worktree --version=VERSION --out=DIR --offline-fixture');
            }
            packageOfflineFixture(dirname(__DIR__), $options['version'], $options['out']);
            echo "Nonrelease offline fixture built from worktree; dependency graph is synthetic and no attestation was created.\n";
            exit(0);
        }

        if ($argc === 9 && $argv[1] === '--verify-producer-manifest') {
            packageVerifyProducerManifest(...array_slice($argv, 2));
            echo "Producer manifest and split archive verified; tag publication and attestation remain separate.\n";
            exit(0);
        }

        if ($argc === 6 && $argv[1] === '--build-manifest') {
            $workflow = null;

            if (getenv('GITHUB_ACTIONS') === 'true') {
                $workflow = [
                    'repository' => getenv('GITHUB_REPOSITORY'), 'source_sha' => getenv('GITHUB_SHA'),
                    'tag' => getenv('GITHUB_REF_NAME'), 'workflow_sha' => getenv('GITHUB_WORKFLOW_SHA'),
                    'run_id' => getenv('GITHUB_RUN_ID'), 'run_attempt' => getenv('GITHUB_RUN_ATTEMPT'),
                ];
            }
            $mapping = packageBuildManifest(dirname(__DIR__), $argv[2], $argv[3], $argv[4], $workflow);
            $encoded = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            if (file_put_contents($argv[5], $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('cannot write package manifest');
            }
            echo 'Package manifest: '.$mapping['qualification']."; split tag and attestation are not yet verified.\n";
            exit(0);
        }

        if ($argc === 6 && $argv[1] === '--check-resolved-sbom') {
            $lock = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
            $bom = json_decode((string) file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($lock) || ! is_array($bom) || ($errors = packageResolvedGraphErrors($lock, $bom, $argv[4], $argv[5])) !== []) {
                throw new RuntimeException('resolved SBOM mismatch: '.implode('; ', $errors ?? []));
            }
            echo "Resolved SBOM matches the supplied lock; schema and audit require separate checks.\n";
            exit(0);
        }

        if ($argc === 10 && $argv[1] === '--local-mapping') {
            $mapping = packageLocalMapping(...array_slice($argv, 2, 7));
            $encoded = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            if (file_put_contents($argv[9], $encoded, LOCK_EX) === false) {
                throw new RuntimeException('cannot write local mapping');
            }
            echo "Local mapping only: nonrelease, unsigned, no dependency qualification.\n";
            exit(0);
        }

        if ($argc !== 6 || ! in_array($argv[1], ['--source-sbom', '--check-source-sbom'], true)) {
            throw new RuntimeException('usage: php bin/package-evidence.php --source-sbom|--check-source-sbom ZIP PACKAGE VERSION SBOM');
        }
        $inventory = packageSourceInventory($argv[2], $argv[3], $argv[4]);

        if ($argv[1] === '--source-sbom') {
            $encoded = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            if (file_put_contents($argv[5], $encoded, LOCK_EX) === false) {
                throw new RuntimeException('cannot write SBOM');
            }
        } else {
            $raw = @file_get_contents($argv[5]);

            if (! is_string($raw) || json_decode($raw, true, 512, JSON_THROW_ON_ERROR) !== $inventory) {
                throw new RuntimeException('source SBOM does not match ZIP');
            }
        }
        fwrite(STDOUT, "Source inventory matches ZIP; schema validation and release qualification are not performed.\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'package evidence: '.$error->getMessage()."\n");
        exit(1);
    }
}
