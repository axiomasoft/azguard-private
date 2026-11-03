<?php

declare(strict_types=1);

require_once __DIR__.'/release-gate.php';
require_once __DIR__.'/verify-package-attestations.php';
require_once __DIR__.'/verify-release-setup.php';

/**
 * Check a collected, locally supplied release-run record against explicit
 * expected identity. Acquisition and authenticity of the record remain the
 * caller's responsibility; this is not proof of artifact provenance.
 *
 * @param array<string, mixed> $data
 * @return list<string>
 */
function candidateSourceEvidenceErrors(array $data, string $expectedSha, string $expectedRun): array
{
    $errors = [];
    if (preg_match('/\A[0-9a-f]{40}\z/', $expectedSha) !== 1 || ($data['sha'] ?? null) !== $expectedSha) {
        $errors[] = 'candidate SHA mismatch';
    }
    if (preg_match('/\A[1-9][0-9]*-[1-9][0-9]*\z/', $expectedRun) !== 1 || ($data['run_id'] ?? null) !== $expectedRun) {
        $errors[] = 'candidate run mismatch';
    }
    // Never allow an evidence file to choose the local security-policy root.
    unset($data['root']);

    return [...$errors, ...releaseGateErrors($data)];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if (($argc === 2 || $argc === 3) && $argv[1] === '--setup') {
            $path = $argv[2] ?? dirname(__DIR__).'/build/release/setup-request.json';
            $raw = @file_get_contents($path);
            $request = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
            if (! is_array($request)) {
                throw new RuntimeException('cannot read setup request');
            }
            $api = static function (string $endpoint, bool $paginate = false): ?array {
                $command = $paginate ? ['gh', 'api', '--paginate', '--slurp', $endpoint] : ['gh', 'api', $endpoint];
                [$exit, $output] = releaseSetupCommand($command);
                $data = $exit === 0 ? json_decode($output, true) : null;
                return is_array($data) ? $data : null;
            };
            $audit = static function (): bool {
                [$exit, $output] = releaseSetupCommand([PHP_BINARY, __DIR__.'/repository-settings-audit.php', '--live', '--json']);
                return $exit === 0 && json_decode($output, true) === [];
            };
            $attestation = static function (string $repo, string $sha): bool {
                $path = tempnam(sys_get_temp_dir(), 'azguard-setup-probe-');
                if ($path === false) {
                    return false;
                }
                try {
                    if (file_put_contents($path, "azguard-attestation-setup-v1\n") === false) {
                        return false;
                    }
                    [$exit, $output] = releaseSetupCommand(['gh', 'attestation', 'verify', $path, '--repo', $repo,
                        '--signer-workflow', $repo.'/.github/workflows/attestation-setup.yml', '--signer-digest', $sha,
                        '--source-ref', 'refs/heads/main', '--source-digest', $sha,
                        '--predicate-type', 'https://slsa.dev/provenance/v1', '--format', 'json']);
                    return $exit === 0 && packageAttestationPredicatesMatch($output, []);
                } finally {
                    unlink($path);
                }
            };
            $errors = releaseSetupLiveErrors($request, dirname(__DIR__), $api, $attestation, $audit, time());
            if ($errors !== []) {
                throw new RuntimeException(implode('; ', $errors));
            }
            echo "Current setup prerequisites verified read-only; candidate qualification and publication remain separate.\n";
            exit(0);
        }
        if ($argc === 8 && $argv[1] === '--candidate-artifacts') {
            $verify = static function (array $command, array $expected): bool {
                $setting = getenv('AZGUARD_ATTEST_RETRIES');
                $retries = is_string($setting) && preg_match('/\A(?:[1-9]|1[0-9]|20)\z/', $setting) ? (int) $setting : 20;
                for ($i = 0; $i < $retries; $i++) {
                    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                    if (! is_resource($process)) {
                        return false;
                    }
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    if (proc_close($process) === 0 && is_string($output) && packageAttestationPredicatesMatch($output, $expected)) {
                        return true;
                    }
                    if ($i + 1 < $retries) {
                        sleep(5);
                    }
                }
                return false;
            };
            packageVerifyAttestations(dirname(__DIR__), $argv[2], $argv[3], $argv[4], $argv[5], $argv[6], $argv[7], $verify);
            echo "Candidate artifacts and trusted attestations verified; split tag publication remains a separate check.\n";
            exit(0);
        }
        if ($argc !== 5 || $argv[1] !== '--candidate-source') {
            throw new RuntimeException('usage: php bin/verify-release-evidence.php --setup [REQUEST] | --candidate-source SHA RUN-ID-ATTEMPT FILE | --candidate-artifacts SHA TAG DIST CORE-SHA FILAMENT-SHA RUN-ID-ATTEMPT');
        }
        $raw = @file_get_contents($argv[4]);
        $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! is_array($data)) {
            throw new RuntimeException('cannot read candidate evidence');
        }
        $errors = candidateSourceEvidenceErrors($data, $argv[2], $argv[3]);
        if ($errors !== []) {
            throw new RuntimeException(implode('; ', $errors));
        }
        echo "Candidate record is internally consistent; authenticate its collection separately. Artifact provenance is not checked.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'release evidence: '.$error->getMessage()."\n");
        exit(1);
    }
}
