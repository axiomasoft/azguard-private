<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-producer-evidence.php';

/**
 * Offline candidate check. The caller must establish workflow/environment trust
 * and authenticate $expected outside this function. This does not authorize or
 * perform an attestation, release, network call or secret-bearing checkout.
 *
 * @param  array{workflow_sha: string, source_sha: string, split_sha: string, tag: string}  $expected
 * @return array{workflow_sha: string, source_sha: string, split_sha: string, tag: string, mode: string}
 */
function trustedProducerInspectSource(string $repository, array $expected, array $gitTool, bool $keepControlled = false): array
{
    $staged = trustedProducerStageInputs(['git' => $gitTool], ['git']);

    try {
        return trustedProducerInspectStagedSource($repository, $expected, $staged['inputs']['git'], $keepControlled);
    } finally {
        packageRemoveFixtureDirectory($staged['directory']);
    }
}

function trustedProducerInspectStagedSource(string $repository, array $expected, array $gitTool, bool $keepControlled = false): array
{
    if (array_keys($gitTool) !== ['path', 'sha256'] || ! is_string($gitTool['path'])
        || ! str_starts_with($gitTool['path'], '/') || is_link($gitTool['path'])
        || ! is_file($gitTool['path']) || ! is_executable($gitTool['path'])
        || ! is_string($gitTool['sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $gitTool['sha256']) !== 1
        || ! hash_equals($gitTool['sha256'], (string) hash_file('sha256', $gitTool['path']))) {
        throw new RuntimeException('untrusted approved Git executable');
    }
    $gitPath = $gitTool['path'];
    $keys = array_keys($expected);
    sort($keys);

    if ($keys !== ['source_sha', 'split_sha', 'tag', 'workflow_sha']) {
        throw new RuntimeException('incomplete approved identity');
    }
    foreach (['workflow_sha', 'source_sha', 'split_sha'] as $key) {
        if (! is_string($expected[$key]) || preg_match('/\A[0-9a-f]{40}\z/', $expected[$key]) !== 1) {
            throw new RuntimeException('invalid approved identity');
        }
    }

    if (! is_string($expected['tag']) || preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $expected['tag']) !== 1) {
        throw new RuntimeException('invalid tag');
    }
    $key = getenv('RELEASE_TAG_PUBLIC_KEY');
    $fingerprint = getenv('RELEASE_TAG_SIGNER_FINGERPRINT');

    if (! is_string($key) || $key === '' || ! is_string($fingerprint)
        || preg_match('/\A(?:[0-9A-F]{40}|[0-9A-F]{64})\z/', $fingerprint) !== 1) {
        throw new RuntimeException('independently trusted public signing key required');
    }
    $gitDir = rtrim($repository, '/').'/.git';

    if (is_link($gitDir) || ! is_dir($gitDir) || is_link($gitDir.'/objects') || ! is_dir($gitDir.'/objects')) {
        throw new RuntimeException('untrusted source object directory');
    }
    $work = sys_get_temp_dir().'/azguard-isolated-source-'.bin2hex(random_bytes(12));

    if (! mkdir($work, 0700) || ! mkdir($work.'/repo', 0700)) {
        throw new RuntimeException('cannot create isolated source');
    }
    $success = false;

    try {
        trustedProducerOfflineCommand([$gitPath, 'init', '-q', $work.'/repo'], $work);
        // Copy only supported object files and the requested loose tag.
        // Source configuration, hooks and other refs never enter the controlled repo.
        foreach (new FilesystemIterator($gitDir.'/objects', FilesystemIterator::SKIP_DOTS) as $bucket) {
            $name = $bucket->getFilename();

            if ($name === 'info') {
                if ($bucket->isLink() || ! $bucket->isDir()) {
                    throw new RuntimeException('unsafe object metadata directory');
                }
                foreach (new FilesystemIterator($bucket->getPathname(), FilesystemIterator::SKIP_DOTS) as $metadata) {
                    if (! in_array($metadata->getFilename(), ['packs', 'commit-graph'], true) || $metadata->isLink() || ! $metadata->isFile()) {
                        throw new RuntimeException('alternate object stores are unsupported');
                    }
                }

                continue;
            }

            if ($name === 'pack') {
                if ($bucket->isLink() || ! $bucket->isDir()) {
                    throw new RuntimeException('unsafe pack directory');
                }
                $packs = [];
                foreach (new FilesystemIterator($bucket->getPathname(), FilesystemIterator::SKIP_DOTS) as $file) {
                    if ($file->isLink() || ! $file->isFile()
                        || preg_match('/\A(pack-[0-9a-f]{40})\.(pack|idx|rev)\z/', $file->getFilename(), $match) !== 1) {
                        throw new RuntimeException('unsupported or unsafe pack file');
                    }
                    $packs[$match[1]][$match[2]] = $file->getPathname();
                }
                foreach ($packs as $base => $parts) {
                    if (! isset($parts['pack'], $parts['idx'])) {
                        throw new RuntimeException('incomplete pack and index pair');
                    }
                    foreach ($parts as $extension => $path) {
                        if (! copy($path, $work.'/repo/.git/objects/pack/'.$base.'.'.$extension)) {
                            throw new RuntimeException('cannot copy pack file');
                        }
                    }

                    if (isset($parts['rev'])) {
                        $reverse = (string) file_get_contents($work.'/repo/.git/objects/pack/'.$base.'.rev');

                        if (strlen($reverse) < 52 || substr($reverse, 0, 12) !== "RIDX\0\0\0\1\0\0\0\1"
                            || bin2hex(substr($reverse, -40, 20)) !== substr($base, 5)
                            || ! hash_equals(substr($reverse, -20), hash('sha1', substr($reverse, 0, -20), true))) {
                            throw new RuntimeException('invalid reverse pack index');
                        }
                    }
                    trustedProducerOfflineCommand([$gitPath, '-C', $work.'/repo', 'verify-pack', '-v',
                        $work.'/repo/.git/objects/pack/'.$base.'.idx'], $work);
                }

                continue;
            }

            if ($bucket->isLink() || ! $bucket->isDir() || preg_match('/\A[0-9a-f]{2}\z/', $name) !== 1) {
                throw new RuntimeException('unsafe object directory');
            }
            $destination = $work.'/repo/.git/objects/'.$name;

            if (! mkdir($destination, 0700)) {
                throw new RuntimeException('cannot create object bucket');
            }
            foreach (new FilesystemIterator($bucket->getPathname(), FilesystemIterator::SKIP_DOTS) as $object) {
                if ($object->isLink() || ! $object->isFile() || preg_match('/\A[0-9a-f]{38}\z/', $object->getFilename()) !== 1
                    || ! copy($object->getPathname(), $destination.'/'.$object->getFilename())) {
                    throw new RuntimeException('unsafe object');
                }
            }
        }
        foreach (['refs', 'refs/tags'] as $segment) {
            if (is_link($gitDir.'/'.$segment) || (file_exists($gitDir.'/'.$segment) && ! is_dir($gitDir.'/'.$segment))) {
                throw new RuntimeException('unsafe tag directory');
            }
        }
        $tagPath = $gitDir.'/refs/tags/'.$expected['tag'];
        $tagObject = null;

        if (is_link($tagPath)) {
            throw new RuntimeException('unsafe loose tag');
        }

        if (file_exists($tagPath)) {
            if (! is_file($tagPath) || preg_match('/\A([0-9a-f]{40})\n\z/', (string) file_get_contents($tagPath), $loose) !== 1) {
                throw new RuntimeException('invalid loose tag');
            }
            $tagObject = $loose[1];
        } else {
            $packedPath = $gitDir.'/packed-refs';

            if (is_link($packedPath) || ! is_file($packedPath) || filesize($packedPath) > 16 * 1024 * 1024) {
                throw new RuntimeException('missing or unsafe packed refs');
            }
            $packed = file_get_contents($packedPath);

            if (! is_string($packed) || ! str_ends_with($packed, "\n")) {
                throw new RuntimeException('invalid packed refs');
            }
            $previousRef = false;
            foreach (explode("\n", substr($packed, 0, -1)) as $line) {
                if (str_starts_with($line, '#')) {
                    $previousRef = false;

                    continue;
                }

                if (preg_match('/\A\^[0-9a-f]{40}\z/', $line) === 1 && $previousRef) {
                    $previousRef = false;

                    continue;
                }

                if (preg_match('~\A([0-9a-f]{40}) (refs/[^\s\x00-\x1f]+)\z~', $line, $match) !== 1) {
                    throw new RuntimeException('invalid packed refs entry');
                }
                $previousRef = true;

                if ($match[2] === 'refs/tags/'.$expected['tag']) {
                    if ($tagObject !== null) {
                        throw new RuntimeException('duplicate packed tag');
                    }
                    $tagObject = $match[1];
                }
            }
        }

        if ($tagObject === null || file_put_contents($work.'/repo/.git/refs/tags/'.$expected['tag'], $tagObject."\n") === false) {
            throw new RuntimeException('missing requested tag');
        }
        file_put_contents($work.'/repo/.git/HEAD', $expected['source_sha']."\n");
        trustedProducerOfflineCommand([$gitPath, '-C', $work.'/repo', 'fsck', '--full', '--strict'], $work);
        $env = ['PATH' => '/usr/bin:/bin', 'HOME' => $work, 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
            'RELEASE_TAG_PUBLIC_KEY' => $key, 'RELEASE_TAG_SIGNER_FINGERPRINT' => $fingerprint];
        $process = proc_open([PHP_BINARY, __DIR__.'/trusted-isolated-tag-verifier.php', $work.'/repo', $expected['tag'], $expected['source_sha']],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('isolated signed tag verification failed');
        }

        if (! mkdir($work.'/repo/packages/core', 0700, true)) {
            throw new RuntimeException('cannot create isolated subtree path');
        }
        $split = trim(trustedProducerOfflineCommand([$gitPath, '-C', $work.'/repo', '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.fsmonitor=false', 'subtree', 'split', '--prefix=packages/core', $expected['source_sha']], $work));

        if (! hash_equals($expected['split_sha'], $split)) {
            throw new RuntimeException('source and core split do not match');
        }
        $success = true;

        return [...$expected, 'mode' => 'offline-candidate-only', ...($keepControlled ? ['controlled_work' => $work, 'controlled_repository' => $work.'/repo'] : [])];
    } finally {
        if (! $success || ! $keepControlled) {
            packageRemoveFixtureDirectory($work);
        }
    }
}

/** Run trusted, explicitly pinned tools without inheriting credential-bearing environment. */
function trustedProducerOfflineCommand(array $argv, string $home): string
{
    $env = ['PATH' => '/usr/bin:/bin', 'HOME' => $home, 'COMPOSER_HOME' => $home.'/composer',
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0'];
    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);

    if (! is_resource($process)) {
        throw new RuntimeException('cannot start offline producer command');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    if (proc_close($process) !== 0 || ! is_string($output)) {
        throw new RuntimeException('offline producer command failed');
    }

    return $output;
}

/** Stage approved bytes before any use; paths in the returned directory are private copies. */
function trustedProducerStageInputs(array $inputs, array $executables): array
{
    $directory = sys_get_temp_dir().'/azguard-approved-inputs-'.bin2hex(random_bytes(12));

    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('cannot create approved input workspace');
    }

    try {
        foreach ($inputs as $name => $input) {
            if (! is_string($name) || preg_match('/\A[a-z_]+\z/', $name) !== 1 || ! is_array($input)
                || array_keys($input) !== ['path', 'sha256'] || ! is_string($input['path'])
                || ! str_starts_with($input['path'], '/') || is_link($input['path']) || ! is_file($input['path'])
                || ! is_string($input['sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $input['sha256']) !== 1
                || (in_array($name, $executables, true) && ! is_executable($input['path']))) {
                throw new RuntimeException('invalid approved input: '.$name);
            }
            $source = @fopen($input['path'], 'rb');
            $target = @fopen($directory.'/'.$name, 'xb');

            if ($source === false || $target === false) {
                if (is_resource($source)) {
                    fclose($source);
                }

                if (is_resource($target)) {
                    fclose($target);
                }

                throw new RuntimeException('cannot stage approved input');
            }

            try {
                $stat = fstat($source);

                if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException('input is not a regular file');
                }
            } finally {
                fclose($source);
                fclose($target);
            }
            $path = $directory.'/'.$name;

            if (! hash_equals($input['sha256'], (string) hash_file('sha256', $path))
                || ! chmod($path, in_array($name, $executables, true) ? 0500 : 0400)) {
                throw new RuntimeException('approved input digest mismatch: '.$name);
            }
            $inputs[$name]['path'] = $path;
        }

        return ['directory' => $directory, 'inputs' => $inputs];
    } catch (Throwable $error) {
        packageRemoveFixtureDirectory($directory);

        throw $error;
    }
}

/**
 * Build a nonrelease candidate from an already authenticated source repository.
 * Tool paths/hashes and dependency evidence are independently approved inputs;
 * injected signature checking is for offline composition and is not live proof.
 *
 * @param  array<string, mixed>  $identity
 * @param  array<string, array{path: string, sha256: string}>  $inputs
 * @return array<string, mixed>
 */
function trustedProducerBuildOffline(string $repository, array $identity, array $inputs, string $output, array $sourceRun): array
{
    $staged = trustedProducerStageInputs($inputs, ['composer', 'git', 'python', 'tar', 'validator']);

    try {
        return trustedProducerBuildStagedOffline($repository, $identity, $staged['inputs'], $output, $sourceRun);
    } finally {
        packageRemoveFixtureDirectory($staged['directory']);
    }
}

function trustedProducerBuildStagedOffline(string $repository, array $identity, array $inputs, string $output, array $sourceRun): array
{
    if (getenv('GH_TOKEN') || getenv('GITHUB_TOKEN') || getenv('PRIVATE_RELEASE_READ_TOKEN')) {
        throw new RuntimeException('offline builder must not receive credentials');
    }
    $runKeys = array_keys($sourceRun);
    sort($runKeys);

    if ($runKeys !== ['run_attempt', 'run_id']
        || ! is_string($sourceRun['run_id']) || preg_match('/\A[1-9][0-9]*\z/', $sourceRun['run_id']) !== 1
        || ! is_string($sourceRun['run_attempt']) || preg_match('/\A[1-9][0-9]*\z/', $sourceRun['run_attempt']) !== 1) {
        throw new RuntimeException('invalid independently approved release run');
    }
    $expectedInputKeys = ['composer', 'dependency_sbom', 'git', 'lock', 'normalizer', 'python', 'tar', 'validator'];
    $inputKeys = array_keys($inputs);
    sort($inputKeys);

    if ($inputKeys !== $expectedInputKeys) {
        throw new RuntimeException('incomplete approved tools or evidence');
    }
    foreach ($inputs as $name => $input) {
        if (! is_array($input) || array_keys($input) !== ['path', 'sha256']
            || ! is_string($input['path']) || ! is_string($input['sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $input['sha256']) !== 1
            || is_link($input['path']) || ! is_file($input['path'])
            || ! hash_equals($input['sha256'], (string) hash_file('sha256', $input['path']))) {
            throw new RuntimeException('untrusted offline producer input: '.$name);
        }
    }
    foreach (['composer', 'git', 'python', 'tar', 'validator'] as $name) {
        if (! is_executable($inputs[$name]['path'])) {
            throw new RuntimeException('offline producer tool is not executable: '.$name);
        }
    }

    if (is_link($output) || ! is_dir($output) || (new FilesystemIterator($output, FilesystemIterator::SKIP_DOTS))->valid()) {
        throw new RuntimeException('offline output must be an existing empty directory');
    }
    $inspect = trustedProducerInspectSource($repository, $identity, $inputs['git'], true);
    $repository = $inspect['controlled_repository'];
    $version = substr($inspect['tag'], 1);
    $work = sys_get_temp_dir().'/azguard-trusted-build-'.bin2hex(random_bytes(12));

    if (! mkdir($work, 0700) || ! mkdir($work.'/package', 0700) || ! mkdir($work.'/built', 0700)) {
        packageRemoveFixtureDirectory($inspect['controlled_work']);

        throw new RuntimeException('cannot create offline build workspace');
    }

    try {
        // Reject non-regular entries before extraction; neither checkout nor any
        // source-provided script or Composer plugin is executed.
        $tree = trustedProducerOfflineCommand([$inputs['git']['path'], '-C', $repository, '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.fsmonitor=false', 'ls-tree', '-r', '-z', $inspect['split_sha']], $work);
        foreach (explode("\0", rtrim($tree, "\0")) as $entry) {
            if (! preg_match('~\A100(?:644|755) blob [0-9a-f]{40}\t([^\x00-\x1f\\\\]+)\z~', $entry, $match)
                || str_starts_with($match[1], '/') || preg_match('~(?:\A|/)(?:\.|\.\.|\.git)(?:/|\z)|//~i', $match[1])) {
                throw new RuntimeException('unsafe split tree entry');
            }
        }
        trustedProducerOfflineCommand([$inputs['git']['path'], '-C', $repository, '-c', 'core.hooksPath=/dev/null',
            'archive', '--format=tar', '--output='.$work.'/source.tar', $inspect['split_sha']], $work);
        trustedProducerOfflineCommand([$inputs['tar']['path'], '--no-same-owner', '--no-same-permissions', '-xf', $work.'/source.tar', '-C', $work.'/package'], $work);
        $manifestPath = $work.'/package/composer.json';

        if (is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('missing core package manifest');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest) || ($manifest['name'] ?? null) !== 'axiomasoft/azguard') {
            throw new RuntimeException('source package identity mismatch');
        }
        $manifest['version'] = $version;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        trustedProducerOfflineCommand([$inputs['composer']['path'], 'archive', '--format=zip', '--dir='.$work.'/built',
            '--working-dir='.$work.'/package', '--no-interaction', '--no-plugins', '--no-scripts'], $work);
        $archives = glob($work.'/built/*.zip') ?: [];

        if (count($archives) !== 1 || is_link($archives[0])) {
            throw new RuntimeException('expected exactly one core archive');
        }
        trustedProducerOfflineCommand([$inputs['python']['path'], $inputs['normalizer']['path'], $archives[0]], $work);
        $zipName = 'azguard-core-'.$version.'.zip';
        $zipPath = $work.'/'.$zipName;

        if (! copy($archives[0], $zipPath)) {
            throw new RuntimeException('cannot stage core archive');
        }
        $source = packageSourceInventory($zipPath, 'axiomasoft/azguard', $version);
        $sourcePath = $work.'/source-core.cdx.json';
        file_put_contents($sourcePath, json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $lock = json_decode((string) file_get_contents($inputs['lock']['path']), true, 512, JSON_THROW_ON_ERROR);
        $bom = json_decode((string) file_get_contents($inputs['dependency_sbom']['path']), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($lock) || ! is_array($bom) || packageResolvedGraphErrors($lock, $bom, 'axiomasoft/azguard', $version) !== []) {
            throw new RuntimeException('approved dependency SBOM and lock graph mismatch');
        }
        foreach ([$sourcePath, $inputs['dependency_sbom']['path']] as $path) {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $spec = $document['specVersion'] ?? null;

            if (! in_array($spec, ['1.4', '1.5', '1.6'], true)) {
                throw new RuntimeException('unsupported CycloneDX version');
            }
            trustedProducerOfflineCommand([$inputs['validator']['path'], 'validate', '--input-file', $path,
                '--input-format', 'json', '--input-version', 'v'.str_replace('.', '_', $spec), '--fail-on-errors'], $work);
        }
        $payload = [$zipName => $zipPath, 'source-core.cdx.json' => $sourcePath,
            'sbom-core.cdx.json' => $inputs['dependency_sbom']['path'], 'resolution-core.lock' => $inputs['lock']['path']];
        $mapping = ['schema' => 'azguard.trusted-offline-build.v1', 'qualification' => 'nonrelease',
            'workflow_sha' => $identity['workflow_sha'], 'source_sha' => $identity['source_sha'],
            'split_sha' => $identity['split_sha'], 'tag' => $identity['tag'],
            'run_id' => $sourceRun['run_id'], 'run_attempt' => $sourceRun['run_attempt'], 'files' => []];
        foreach ($payload as $name => $path) {
            $mapping['files'][$name] = hash_file('sha256', $path);
        }
        $mappingPath = $work.'/mapping-core.json';
        file_put_contents($mappingPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $payload['mapping-core.json'] = $mappingPath;
        $sums = '';
        foreach ($payload as $name => $path) {
            $sums .= hash_file('sha256', $path).'  '.$name."\n";

            if (! copy($path, $output.'/'.$name)) {
                throw new RuntimeException('cannot write offline artifact');
            }
        }
        file_put_contents($output.'/SHA256SUMS', $sums);

        $predicate = trustedProducerPredicate([
            ...$identity, ...$sourceRun,
            'zip_sha256' => $mapping['files'][$zipName],
            'source_sbom_sha256' => $mapping['files']['source-core.cdx.json'],
            'dependency_sbom_sha256' => $mapping['files']['sbom-core.cdx.json'],
            'lock_sha256' => $mapping['files']['resolution-core.lock'],
            'mapping_sha256' => hash_file('sha256', $mappingPath),
        ]);

        return ['qualification' => 'nonrelease', 'mapping' => $mapping, 'predicate' => $predicate];
    } finally {
        packageRemoveFixtureDirectory($work);
        packageRemoveFixtureDirectory($inspect['controlled_work']);
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    fwrite(STDERR, "trusted producer disabled: independently verified protected workflow, environment and credential boundary are required\n");
    exit(1);
}
