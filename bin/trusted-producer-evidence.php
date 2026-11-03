<?php

declare(strict_types=1);

require_once __DIR__.'/verify-package-attestations.php';

/**
 * Candidate verifier for a future trusted-main producer. $approved must come
 * from the separately authenticated setup/qualification boundary, never from
 * the attestation or caller-provided staging tree. No workflow uses this yet.
 *
 * @param array<string, mixed> $approved
 * @param callable(list<string>, list<object>): bool $verify
 */
function trustedProducerVerify(string $directory, array $approved, callable $verify): void
{
    $keys = ['workflow_sha', 'source_sha', 'split_sha', 'tag', 'run_id', 'run_attempt',
        'zip_sha256', 'source_sbom_sha256', 'dependency_sbom_sha256', 'mapping_sha256'];
    $actual = array_keys($approved);
    sort($actual);
    $sorted = $keys;
    sort($sorted);
    if ($actual !== $sorted) {
        throw new RuntimeException('incomplete approved producer identity');
    }
    foreach (['workflow_sha', 'source_sha', 'split_sha'] as $key) {
        if (! is_string($approved[$key]) || preg_match('/\A[0-9a-f]{40}\z/', $approved[$key]) !== 1) {
            throw new RuntimeException('invalid approved '.$key);
        }
    }
    foreach (['zip_sha256', 'source_sbom_sha256', 'dependency_sbom_sha256', 'mapping_sha256'] as $key) {
        if (! is_string($approved[$key]) || preg_match('/\A[0-9a-f]{64}\z/', $approved[$key]) !== 1) {
            throw new RuntimeException('invalid approved '.$key);
        }
    }
    if (! is_string($approved['tag']) || preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $approved['tag']) !== 1
        || ! is_string($approved['run_id']) || preg_match('/\A[1-9][0-9]*\z/', $approved['run_id']) !== 1
        || ! is_string($approved['run_attempt']) || preg_match('/\A[1-9][0-9]*\z/', $approved['run_attempt']) !== 1) {
        throw new RuntimeException('invalid candidate or run identity');
    }
    $directory = realpath($directory) ?: throw new RuntimeException('missing producer artifacts');
    $version = substr($approved['tag'], 1);
    $paths = [
        'zip_sha256' => 'azguard-core-'.$version.'.zip',
        'source_sbom_sha256' => 'source-core.cdx.json',
        'dependency_sbom_sha256' => 'sbom-core.cdx.json',
        'mapping_sha256' => 'mapping-core.json',
    ];
    foreach ($paths as $key => $name) {
        $path = $directory.'/'.$name;
        if (is_link($path) || ! is_file($path) || ! hash_equals($approved[$key], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('untrusted producer artifact: '.$name);
        }
    }
    $predicate = (object) [
        'schema' => 'azguard.trusted-producer.v1',
        'producer_event' => 'workflow_dispatch',
        'workflow_sha' => $approved['workflow_sha'],
        'source_sha' => $approved['source_sha'],
        'split_sha' => $approved['split_sha'],
        'tag' => $approved['tag'],
        'run_id' => $approved['run_id'],
        'run_attempt' => $approved['run_attempt'],
        'zip_sha256' => $approved['zip_sha256'],
        'source_sbom_sha256' => $approved['source_sbom_sha256'],
        'dependency_sbom_sha256' => $approved['dependency_sbom_sha256'],
        'mapping_sha256' => $approved['mapping_sha256'],
    ];
    $command = [
        'gh', 'attestation', 'verify', $directory.'/'.$paths['zip_sha256'],
        '--repo', 'axiomasoft/azguard',
        '--signer-workflow', 'axiomasoft/azguard/.github/workflows/trusted-producer.yml',
        '--signer-digest', $approved['workflow_sha'],
        '--source-digest', $approved['workflow_sha'],
        '--source-ref', 'refs/heads/main',
        '--predicate-type', 'https://axiomasoft.github.io/azguard/attestations/trusted-producer/v1',
        '--format', 'json',
    ];
    if ($verify($command, [$predicate]) !== true) {
        throw new RuntimeException('trusted producer attestation unavailable or mismatched');
    }
}
