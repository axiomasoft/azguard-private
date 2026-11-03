<?php

declare(strict_types=1);

require_once __DIR__.'/package-evidence.php';

/** JSON equality that distinguishes objects, arrays and scalar types while ignoring object key order. */
function packageCanonicalJson(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (is_object($item)) {
            $properties = get_object_vars($item);
            ksort($properties, SORT_STRING);
            $out = new stdClass;
            foreach ($properties as $key => $property) {
                $out->{$key} = $normalize($property);
            }

            return $out;
        }

        return is_array($item) ? array_map($normalize, $item) : $item;
    };

    return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** Require exact predicates returned by an independently trusted gh attestation verification. */
function packageAttestationPredicatesMatch(string $verified, array $expected): bool
{
    try {
        $entries = json_decode($verified, false, 512, JSON_THROW_ON_ERROR);

        if (! is_array($entries) || $entries === []) {
            return false;
        }
        $found = [];
        foreach ($entries as $entry) {
            if (is_object($entry) && isset($entry->verificationResult->statement->predicate)) {
                $found[] = packageCanonicalJson($entry->verificationResult->statement->predicate);
            }
        }

        if ($found === []) {
            return false;
        }
        foreach ($expected as $item) {
            if (! in_array(packageCanonicalJson($item), $found, true)) {
                return false;
            }
        }

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Enforce exact names and hashes; never let a checksum file choose paths to read. */
function packageVerifyChecksums(string $dist, string $version): void
{
    $names = ['azguard-core-'.$version.'.zip', 'source-core.cdx.json', 'sbom-core.cdx.json',
        'resolution-core.lock', 'mapping-core.json'];
    $entries = scandir($dist);
    $allowed = [...$names, 'SHA256SUMS'];

    if (! is_array($entries) || array_diff($entries, ['.', '..', ...$allowed]) !== []) {
        throw new RuntimeException('unexpected release artifact');
    }
    $path = $dist.'/SHA256SUMS';

    if (is_link($path) || ! is_file($path)) {
        throw new RuntimeException('missing checksum file');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if (! is_array($lines) || count($lines) !== count($names)) {
        throw new RuntimeException('invalid checksum list');
    }
    $seen = [];
    foreach ($lines as $line) {
        if (! preg_match('/\A([0-9a-f]{64})  ([A-Za-z0-9.-]+)\z/', $line, $match)
            || ! in_array($match[2], $names, true) || isset($seen[$match[2]])) {
            throw new RuntimeException('invalid checksum entry');
        }
        $file = $dist.'/'.$match[2];

        if (is_link($file) || ! is_file($file) || ! hash_equals($match[1], (string) hash_file('sha256', $file))) {
            throw new RuntimeException('artifact checksum mismatch');
        }
        $seen[$match[2]] = true;
    }
}

/** Verify signed tag separately, using an explicitly configured key and fingerprint. */
function packageVerifySignedTag(string $root, string $tag, string $sha): void
{
    $key = getenv('RELEASE_TAG_PUBLIC_KEY');
    $fingerprint = getenv('RELEASE_TAG_SIGNER_FINGERPRINT');

    if (! is_string($key) || $key === '' || ! is_string($fingerprint)
        || ! preg_match('/\A(?:[0-9A-F]{40}|[0-9A-F]{64})\z/', $fingerprint)) {
        throw new RuntimeException('trusted tag key or fingerprint unavailable');
    }
    $git = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $root, ...$args]));

    if ($git('cat-file', '-t', 'refs/tags/'.$tag) !== 'tag'
        || $git('rev-parse', '--verify', 'refs/tags/'.$tag.'^{commit}') !== $sha
        || $git('rev-parse', '--verify', 'HEAD^{commit}') !== $sha) {
        throw new RuntimeException('signed tag target mismatch');
    }
    $home = sys_get_temp_dir().'/azguard-tag-verification-'.bin2hex(random_bytes(12));

    if (! mkdir($home, 0700)) {
        throw new RuntimeException('cannot create verification keyring');
    }
    $env = getenv();
    $env['GNUPGHOME'] = $home;

    try {
        $process = proc_open(['gpg', '--batch', '--quiet', '--import'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('cannot start gpg');
        }
        fwrite($pipes[0], $key);
        fclose($pipes[0]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('cannot import trusted tag key');
        }
        $process = proc_open(['git', '-C', $root, 'verify-tag', '--raw', 'refs/tags/'.$tag], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('cannot start tag verification');
        }
        $status = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        preg_match_all('/^\[GNUPG:\] VALIDSIG ([0-9A-F]+) /m', (string) $status, $matches);

        if ($exit !== 0 || ($matches[1] ?? []) !== [$fingerprint]) {
            throw new RuntimeException('untrusted or invalid tag signature');
        }
    } finally {
        $process = proc_open(['gpgconf', '--kill', 'gpg-agent'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);

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

/**
 * Validate source, actual artifacts and attestations. $verify is injectable
 * only for local tests; the CLI always calls gh with fixed trust constraints.
 */
function packageVerifyAttestations(string $root, string $sha, string $tag, string $dist, string $core, string $run, callable $verify): void
{
    if (! preg_match('/\A[0-9a-f]{40}\z/', $sha) || ! preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $tag)
        || ! preg_match('/\A[0-9a-f]{40}\z/', $core)
        || ! preg_match('/\A([1-9][0-9]*)-([1-9][0-9]*)\z/', $run, $match)) {
        throw new RuntimeException('invalid candidate identity');
    }
    $root = realpath($root) ?: throw new RuntimeException('source directory missing');
    $dist = realpath($dist) ?: throw new RuntimeException('artifact directory missing');
    $version = substr($tag, 1);
    packageVerifySignedTag($root, $tag, $sha);
    packageVerifyChecksums($dist, $version);
    foreach (['core' => $core] as $package => $splitSha) {
        $computed = trim(packageEvidenceCommand(['git', '-C', $root, 'subtree', 'split', '--prefix=packages/'.$package, $sha]));

        if ($computed !== $splitSha) {
            throw new RuntimeException('split commit mismatch');
        }
        packageVerifyProducerManifest($root, $root, $dist, $package, $tag, $match[1], $match[2], $splitSha);
        foreach (['source-'.$package.'.cdx.json', 'sbom-'.$package.'.cdx.json'] as $file) {
            $bom = json_decode((string) file_get_contents($dist.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
            $spec = $bom['specVersion'] ?? null;

            if (! in_array($spec, ['1.4', '1.5', '1.6'], true)) {
                throw new RuntimeException('unsupported SBOM version');
            }
            packageEvidenceCommand(['bash', dirname(__DIR__).'/bin/security-tools.sh', 'cyclonedx-cli', '--', 'validate', '--input-file', $dist.'/'.$file, '--input-format', 'json', '--input-version', 'v'.str_replace('.', '_', $spec), '--fail-on-errors']);
        }
        $repo = 'axiomasoft/azguard';
        $zip = $dist.'/azguard-'.$package.'-'.$version.'.zip';
        $base = ['gh', 'attestation', 'verify', $zip, '--repo', $repo, '--signer-workflow', $repo.'/.github/workflows/provenance.yml',
            '--signer-digest', $splitSha, '--source-digest', $splitSha,
            '--source-ref', 'refs/heads/release-candidate/'.$tag.'/'.$match[1].'/'.$match[2]];
        foreach ([
            'https://slsa.dev/provenance/v1' => null,
            'https://cyclonedx.org/bom' => ['source-'.$package.'.cdx.json', 'sbom-'.$package.'.cdx.json'],
            'https://axiomasoft.github.io/azguard/attestations/package-mapping/v1' => ['mapping-'.$package.'.json'],
        ] as $predicate => $files) {
            $expected = [];
            foreach ($files ?? [] as $file) {
                $expected[] = json_decode((string) file_get_contents($dist.'/'.$file), false, 512, JSON_THROW_ON_ERROR);
            }
            $verified = $verify([...$base, '--predicate-type', $predicate, '--format', 'json'], $expected);

            if ($verified !== true) {
                throw new RuntimeException('unverified '.$package.' attestation: '.$predicate);
            }
        }
    }
}
