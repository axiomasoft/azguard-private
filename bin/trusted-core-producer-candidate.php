<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-producer-evidence.php';

/**
 * Offline candidate check. The caller must establish workflow/environment trust
 * and authenticate $expected outside this function. This does not authorize or
 * perform an attestation, release, network call or secret-bearing checkout.
 *
 * @param array{workflow_sha: string, source_sha: string, split_sha: string, tag: string} $expected
 * @param callable(string, string, string): void $verifySignedTag
 * @return array{workflow_sha: string, source_sha: string, split_sha: string, tag: string, mode: string}
 */
function trustedProducerInspectSource(string $repository, array $expected, callable $verifySignedTag): array
{
    $keys = array_keys($expected);
    sort($keys);
    if ($keys !== ['source_sha', 'split_sha', 'tag', 'workflow_sha']) {
        throw new RuntimeException('incomplete independent identity');
    }
    foreach (['workflow_sha', 'source_sha', 'split_sha'] as $key) {
        if (! is_string($expected[$key]) || preg_match('/\A[0-9a-f]{40}\z/', $expected[$key]) !== 1) {
            throw new RuntimeException('invalid approved '.$key);
        }
    }
    if (! is_string($expected['tag']) || preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $expected['tag']) !== 1) {
        throw new RuntimeException('invalid tag');
    }
    $repository = realpath($repository) ?: throw new RuntimeException('missing source repository');

    // No source-derived script is loaded. The real verifier checks an annotated
    // tag against a separately provided trusted key before any source inspection.
    $verifySignedTag($repository, $expected['tag'], $expected['source_sha']);
    $split = trim(packageEvidenceCommand(['git', '-C', $repository, 'subtree', 'split', '--prefix=packages/core', $expected['source_sha']]));
    if (! hash_equals($expected['split_sha'], $split)) {
        throw new RuntimeException('source and core split do not match');
    }

    return [...$expected, 'mode' => 'offline-candidate-only'];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    fwrite(STDERR, "trusted producer disabled: independently verified protected workflow, environment and credential boundary are required\n");
    exit(1);
}
