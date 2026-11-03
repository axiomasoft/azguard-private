<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-producer-evidence.php';

/**
 * Offline contract only. The caller must independently authenticate $approved,
 * and $verify must authenticate the same bundle digest against a trusted run.
 * This is not a production trust decision. No production caller is enabled.
 */
function trustedQualificationBundleFixture(string $directory, array $approved, callable $verify): array
{
    $keys = array_keys($approved);
    sort($keys);
    if ($keys !== ['manifest_sha256', 'run_attempt', 'run_id', 'source_sha', 'split_sha', 'tag', 'workflow_sha']) {
        throw new RuntimeException('incomplete independently approved qualification');
    }
    trustedProducerPredicate([...array_diff_key($approved, ['manifest_sha256' => true]),
        'zip_sha256' => str_repeat('0', 64), 'source_sbom_sha256' => str_repeat('0', 64),
        'dependency_sbom_sha256' => str_repeat('0', 64), 'lock_sha256' => str_repeat('0', 64), 'mapping_sha256' => str_repeat('0', 64)]);
    if (! is_string($approved['manifest_sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $approved['manifest_sha256']) !== 1) {
        throw new RuntimeException('invalid approved manifest digest');
    }
    if (is_link($directory) || ! is_dir($directory)) {
        throw new RuntimeException('unsafe bundle directory');
    }
    $files = ['manifest.json', 'producer-config.json', 'resolution-core.lock', 'sbom-core.cdx.json'];
    $entries = array_map(static fn (SplFileInfo $f): string => $f->getFilename(), iterator_to_array(new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS), false));
    sort($entries);
    $expectedEntries = $files;
    sort($expectedEntries);
    if ($entries !== $expectedEntries) {
        throw new RuntimeException('unexpected qualification bundle entries');
    }
    $contents = [];
    foreach ($files as $file) {
        $path = $directory.'/'.$file;
        if (is_link($path) || ! is_file($path) || filesize($path) > 16 * 1024 * 1024) {
            throw new RuntimeException('unsafe qualification bundle file');
        }
        $contents[$file] = file_get_contents($path);
        if (! is_string($contents[$file])) {
            throw new RuntimeException('cannot read qualification bundle');
        }
    }
    if (! hash_equals($approved['manifest_sha256'], hash('sha256', $contents['manifest.json']))) {
        throw new RuntimeException('unapproved qualification manifest');
    }
    $manifest = json_decode($contents['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $identity = array_diff_key($approved, ['manifest_sha256' => true]);
    $expected = ['schema' => 'azguard.qualification-bundle.v1', 'repository' => 'axiomasoft/azguard-private',
        'identity' => $identity, 'files' => []];
    foreach (array_slice($files, 1) as $file) {
        $expected['files'][$file] = hash('sha256', $contents[$file]);
    }
    if (! is_array($manifest) || packageCanonicalJson((object) $manifest) !== packageCanonicalJson((object) $expected)) {
        throw new RuntimeException('qualification identity or file digest mismatch');
    }
    $config = json_decode($contents['producer-config.json'], true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode($contents['resolution-core.lock'], true, 512, JSON_THROW_ON_ERROR);
    $bom = json_decode($contents['sbom-core.cdx.json'], true, 512, JSON_THROW_ON_ERROR);
    $version = substr($approved['tag'], 1);
    $configKeys = is_array($config) ? array_keys($config) : [];
    sort($configKeys);
    $inputKeys = is_array($config['inputs'] ?? null) ? array_keys($config['inputs']) : [];
    sort($inputKeys);
    if ($configKeys !== ['approved_output', 'identity', 'inputs', 'output', 'predicate_output', 'repository', 'source_run']
        || $inputKeys !== ['composer', 'dependency_sbom', 'git', 'lock', 'normalizer', 'python', 'tar', 'validator']) {
        throw new RuntimeException('unexpected qualification config schema');
    }
    foreach ($config['inputs'] as $input) {
        if (! is_array($input) || array_keys($input) !== ['path', 'sha256'] || ! is_string($input['path']) || $input['path'] === ''
            || ! is_string($input['sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $input['sha256']) !== 1) {
            throw new RuntimeException('invalid qualification config input');
        }
    }
    foreach (['output', 'approved_output', 'predicate_output'] as $path) {
        if (! is_string($config[$path]) || ! str_starts_with($config[$path], '/')) {
            throw new RuntimeException('invalid qualification config output');
        }
    }

    if (! is_array($config) || ($config['repository'] ?? null) !== '__SOURCE_REPOSITORY__'
        || ($config['identity'] ?? null) !== array_intersect_key($identity, array_flip(['workflow_sha', 'source_sha', 'split_sha', 'tag']))
        || ($config['source_run'] ?? null) !== ['run_id' => $approved['run_id'], 'run_attempt' => $approved['run_attempt']]
        || ($config['inputs']['lock']['sha256'] ?? null) !== $expected['files']['resolution-core.lock']
        || ($config['inputs']['dependency_sbom']['sha256'] ?? null) !== $expected['files']['sbom-core.cdx.json']
        || ! is_array($lock) || ! is_array($bom) || ($bom['bomFormat'] ?? null) !== 'CycloneDX'
        || ! in_array($bom['specVersion'] ?? null, ['1.4', '1.5', '1.6'], true)
        || ($bom['version'] ?? null) !== 1 || packageResolvedGraphErrors($lock, $bom, 'axiomasoft/azguard', $version) !== []) {
        throw new RuntimeException('qualification config or dependency graph mismatch');
    }
    if ($verify($approved, $directory.'/manifest.json') !== true) {
        throw new RuntimeException('independent qualification authentication unavailable');
    }
    foreach ($contents as $file => $content) {
        clearstatcache(true, $directory.'/'.$file);
        if (is_link($directory.'/'.$file) || ! is_file($directory.'/'.$file)
            || ! hash_equals(hash('sha256', $content), (string) hash_file('sha256', $directory.'/'.$file))) {
            throw new RuntimeException('qualification bundle changed during verification');
        }
    }
    return ['qualification' => 'nonrelease-offline-fixture', 'identity' => $identity, 'files' => $expected['files']];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    fwrite(STDERR, "qualification bundle disabled: independent GitHub run and artifact authentication required\n");
    exit(1);
}
