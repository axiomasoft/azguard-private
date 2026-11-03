<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-qualification-bundle.php';

$root = sys_get_temp_dir().'/azguard-qualification-'.bin2hex(random_bytes(8));

if (! mkdir($root, 0700)) {
    throw new RuntimeException('cannot create fixture');
}

try {
    $identity = ['workflow_sha' => str_repeat('a', 40), 'source_sha' => str_repeat('b', 40), 'split_sha' => str_repeat('c', 40),
        'tag' => 'v2.0.0', 'run_id' => '123', 'run_attempt' => '1'];
    $lock = ['packages' => [['name' => 'axiomasoft/azguard', 'version' => '2.0.0']]];
    $bom = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
        'metadata' => ['component' => ['type' => 'library', 'name' => 'axiomasoft/azguard', 'version' => '2.0.0',
            'purl' => 'pkg:composer/axiomasoft/azguard@2.0.0']], 'components' => []];
    file_put_contents($root.'/resolution-core.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/sbom-core.cdx.json', json_encode($bom, JSON_THROW_ON_ERROR));
    $inputs = [];
    foreach (['composer', 'git', 'normalizer', 'python', 'tar', 'validator'] as $tool) {
        $inputs[$tool] = ['path' => '/approved/'.$tool, 'sha256' => str_repeat('d', 64)];
    }
    $inputs['lock'] = ['path' => '/approved/resolution-core.lock', 'sha256' => hash_file('sha256', $root.'/resolution-core.lock')];
    $inputs['dependency_sbom'] = ['path' => '/approved/sbom-core.cdx.json', 'sha256' => hash_file('sha256', $root.'/sbom-core.cdx.json')];
    $config = ['repository' => '__SOURCE_REPOSITORY__', 'identity' => array_slice($identity, 0, 4, true),
        'source_run' => ['run_id' => '123', 'run_attempt' => '1'], 'inputs' => $inputs,
        'output' => '/approved/output', 'approved_output' => '/approved/evidence.json', 'predicate_output' => '/approved/predicate.json'];
    file_put_contents($root.'/producer-config.json', json_encode($config, JSON_THROW_ON_ERROR));
    $manifest = ['schema' => 'azguard.qualification-bundle.v1', 'repository' => 'axiomasoft/azguard-private', 'identity' => $identity, 'files' => []];
    foreach (['producer-config.json', 'resolution-core.lock', 'sbom-core.cdx.json'] as $name) {
        $manifest['files'][$name] = hash_file('sha256', $root.'/'.$name);
    }
    file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $approved = [...$identity, 'manifest_sha256' => hash_file('sha256', $root.'/manifest.json')];
    // This stub asserts plumbing only: it is not a GitHub signature or run proof.
    $fake = static fn (array $claims, string $path): bool => $claims === $approved && is_file($path);

    if ((trustedQualificationBundleFixture($root, $approved, $fake)['qualification'] ?? '') !== 'nonrelease-offline-fixture') {
        throw new RuntimeException('offline fixture did not validate');
    }
    $reject = static function (callable $case): void {
        try {
            $case();
        } catch (RuntimeException) {
            return;
        }

        throw new RuntimeException('untrusted bundle accepted');
    };
    $reject(static fn () => trustedQualificationBundleFixture($root, $approved, static fn (): bool => false));
    foreach (['source_sha', 'split_sha', 'workflow_sha', 'run_id', 'run_attempt', 'tag', 'manifest_sha256'] as $key) {
        $bad = $approved;
        $bad[$key] = match ($key) {
            'run_id', 'run_attempt' => '9', 'tag' => 'v2.0.1', 'manifest_sha256' => str_repeat('f', 64), default => str_repeat('f', 40)
        };
        $reject(static fn () => trustedQualificationBundleFixture($root, $bad, static fn (): bool => true));
    }
    foreach (['producer-config.json', 'resolution-core.lock', 'sbom-core.cdx.json'] as $file) {
        $path = $root.'/'.$file;
        $before = (string) file_get_contents($path);
        file_put_contents($path, '{}');
        $reject(static fn () => trustedQualificationBundleFixture($root, $approved, static fn (): bool => true));
        file_put_contents($path, $before);
    }
    // Rehash a self-consistent forgery: semantic checks must still reject it.
    $forgedBom = $bom;
    $forgedBom['metadata']['component']['version'] = '9.9.9';
    file_put_contents($root.'/sbom-core.cdx.json', json_encode($forgedBom, JSON_THROW_ON_ERROR));
    $forgedConfig = $config;
    $forgedConfig['inputs']['dependency_sbom']['sha256'] = hash_file('sha256', $root.'/sbom-core.cdx.json');
    file_put_contents($root.'/producer-config.json', json_encode($forgedConfig, JSON_THROW_ON_ERROR));
    $forgedManifest = $manifest;
    $forgedManifest['files']['producer-config.json'] = hash_file('sha256', $root.'/producer-config.json');
    $forgedManifest['files']['sbom-core.cdx.json'] = hash_file('sha256', $root.'/sbom-core.cdx.json');
    file_put_contents($root.'/manifest.json', json_encode($forgedManifest, JSON_THROW_ON_ERROR));
    $forgedApproved = [...$identity, 'manifest_sha256' => hash_file('sha256', $root.'/manifest.json')];
    $reject(static fn () => trustedQualificationBundleFixture($root, $forgedApproved, static fn (): bool => true));
    file_put_contents($root.'/sbom-core.cdx.json', json_encode($bom, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/producer-config.json', json_encode($config, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/extra', 'unexpected');
    $reject(static fn () => trustedQualificationBundleFixture($root, $approved, $fake));
    unlink($root.'/extra');
    $target = $root.'/producer-config.json';
    $original = (string) file_get_contents($target);
    unlink($target);
    symlink($root.'/manifest.json', $target);
    $reject(static fn () => trustedQualificationBundleFixture($root, $approved, $fake));
    unlink($target);
    file_put_contents($target, $original);
    $reject(static fn () => trustedQualificationBundleFixture($root, $approved, static function () use ($target): bool {
        file_put_contents($target, 'changed');

        return true;
    }));
    $process = proc_open([PHP_BINARY, __DIR__.'/trusted-qualification-bundle.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process) || proc_close($process) !== 1) {
        throw new RuntimeException('production bundle CLI was enabled');
    }
    echo "Qualification bundle fixtures passed; independent GitHub authentication remains unavailable.\n";
} finally {
    packageRemoveFixtureDirectory($root);
}
