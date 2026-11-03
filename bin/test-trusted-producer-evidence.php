<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-producer-evidence.php';

$directory = sys_get_temp_dir().'/azguard-trusted-producer-'.bin2hex(random_bytes(8));
if (! mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create fixture directory');
}
try {
    $files = [
        'zip_sha256' => 'azguard-core-2.0.0-rc.1.zip',
        'source_sbom_sha256' => 'source-core.cdx.json',
        'dependency_sbom_sha256' => 'sbom-core.cdx.json',
        'mapping_sha256' => 'mapping-core.json',
    ];
    $approved = [
        'workflow_sha' => str_repeat('a', 40), 'source_sha' => str_repeat('b', 40),
        'split_sha' => str_repeat('c', 40), 'tag' => 'v2.0.0-rc.1',
        'run_id' => '123', 'run_attempt' => '2',
    ];
    foreach ($files as $key => $name) {
        file_put_contents($directory.'/'.$name, 'fixture-'.$name);
        $approved[$key] = hash_file('sha256', $directory.'/'.$name);
    }
    $schema = json_decode((string) file_get_contents(__DIR__.'/trusted-producer-evidence-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $verify = static function (array $command, array $predicates) use ($approved, $schema): bool {
        $expected = (object) ['schema' => 'azguard.trusted-producer.v1', 'producer_event' => 'workflow_dispatch', ...$approved];
        $predicate = $predicates[0] ?? null;
        $object = is_object($predicate) ? (array) $predicate : [];
        $keys = array_keys($object);
        sort($keys);
        $required = $schema['required'];
        sort($required);
        if ($keys !== $required || packageCanonicalJson($predicate) !== packageCanonicalJson($expected)) {
            return false;
        }
        foreach ($schema['properties'] as $name => $rule) {
            if (isset($rule['const']) && $object[$name] !== $rule['const']) {
                return false;
            }
            if (isset($rule['pattern']) && preg_match('~'.$rule['pattern'].'~D', $object[$name]) !== 1) {
                return false;
            }
        }
        return array_slice($command, 0, 3) === ['gh', 'attestation', 'verify']
            && array_slice($command, 4) === [
                '--repo', 'axiomasoft/azguard', '--signer-workflow', 'axiomasoft/azguard/.github/workflows/trusted-producer.yml',
                '--signer-digest', $approved['workflow_sha'], '--source-digest', $approved['workflow_sha'],
                '--source-ref', 'refs/heads/main', '--predicate-type',
                'https://axiomasoft.github.io/azguard/attestations/trusted-producer/v1', '--format', 'json',
            ];
    };
    trustedProducerVerify($directory, $approved, $verify);
    $reject = static function (callable $case): void {
        try {
            $case();
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException('invalid trusted producer evidence accepted');
    };
    // Every identity is independently expected, not read from the statement.
    foreach (['workflow_sha', 'source_sha', 'split_sha'] as $key) {
        $bad = $approved;
        $bad[$key] = str_repeat('d', 40);
        $reject(static fn () => trustedProducerVerify($directory, $bad, $verify));
    }
    foreach (['tag' => 'v2.0.0', 'run_id' => '124', 'run_attempt' => '3'] as $key => $value) {
        $bad = $approved;
        $bad[$key] = $value;
        $reject(static fn () => trustedProducerVerify($directory, $bad, $verify));
    }
    $reject(static fn () => trustedProducerVerify($directory, $approved, static fn (): bool => false));
    foreach ($files as $name) {
        $path = $directory.'/'.$name;
        $original = (string) file_get_contents($path);
        file_put_contents($path, 'tampered');
        $reject(static fn () => trustedProducerVerify($directory, $approved, $verify));
        file_put_contents($path, $original);
    }
    $bad = $approved;
    unset($bad['workflow_sha']);
    $reject(static fn () => trustedProducerVerify($directory, $bad, $verify));
    $bad = $approved;
    $bad['workflow_sha'] = 'main';
    $reject(static fn () => trustedProducerVerify($directory, $bad, $verify));
    echo "Trusted producer candidate fixtures passed; no live producer is enabled.\n";
} finally {
    foreach (glob($directory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
