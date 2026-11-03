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
        'lock_sha256' => 'resolution-core.lock',
        'mapping_sha256' => 'mapping-core.json',
    ];
    $approved = [
        'workflow_sha' => str_repeat('a', 40), 'source_sha' => str_repeat('b', 40),
        'split_sha' => str_repeat('c', 40), 'tag' => 'v2.0.0-rc.1',
        'run_id' => '123', 'run_attempt' => '2',
    ];
    $zip = new ZipArchive;
    if ($zip->open($directory.'/'.$files['zip_sha256'], ZipArchive::CREATE) !== true) {
        throw new RuntimeException('cannot create ZIP fixture');
    }
    $zip->addFromString('composer.json', json_encode(['name' => 'axiomasoft/azguard', 'version' => '2.0.0-rc.1'], JSON_THROW_ON_ERROR));
    $zip->close();
    $source = packageSourceInventory($directory.'/'.$files['zip_sha256'], 'axiomasoft/azguard', '2.0.0-rc.1');
    $dependency = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
        'metadata' => ['component' => ['type' => 'library', 'name' => 'axiomasoft/azguard', 'version' => '2.0.0-rc.1', 'purl' => 'pkg:composer/axiomasoft/azguard@2.0.0-rc.1']], 'components' => []];
    $lock = ['packages' => [['name' => 'axiomasoft/azguard', 'version' => '2.0.0-rc.1']]];
    foreach (['source_sbom_sha256' => $source, 'dependency_sbom_sha256' => $dependency, 'lock_sha256' => $lock] as $key => $value) {
        file_put_contents($directory.'/'.$files[$key], json_encode($value, JSON_THROW_ON_ERROR));
    }
    foreach (array_slice($files, 0, 4, true) as $key => $name) {
        $approved[$key] = hash_file('sha256', $directory.'/'.$name);
    }
    $mapping = ['schema' => 'azguard.trusted-offline-build.v1', 'qualification' => 'nonrelease',
        'workflow_sha' => $approved['workflow_sha'], 'source_sha' => $approved['source_sha'], 'split_sha' => $approved['split_sha'],
        'tag' => $approved['tag'], 'run_id' => $approved['run_id'], 'run_attempt' => $approved['run_attempt'], 'files' => []];
    foreach (array_slice($files, 0, 4, true) as $key => $name) {
        $mapping['files'][$name] = $approved[$key];
    }
    file_put_contents($directory.'/'.$files['mapping_sha256'], json_encode($mapping, JSON_THROW_ON_ERROR));
    $approved['mapping_sha256'] = hash_file('sha256', $directory.'/'.$files['mapping_sha256']);
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
    // Stub output exercises CLI plumbing only; it is not a cryptographic signature.
    file_put_contents($directory.'/gh', "#!/bin/sh\ncat \"$directory/attestation.json\"\n");
    chmod($directory.'/gh', 0700);
    $statement = [['verificationResult' => ['statement' => ['predicate' => trustedProducerPredicate($approved)]]]];
    file_put_contents($directory.'/attestation.json', json_encode($statement, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/verify.json', json_encode(['directory' => $directory, 'approved' => $approved], JSON_THROW_ON_ERROR));
    $runCli = static function () use ($directory): int {
        $env = getenv();
        $env['PATH'] = $directory.':/usr/bin:/bin';
        $process = proc_open([PHP_BINARY, __DIR__.'/trusted-producer-pipeline.php', 'verify', $directory.'/verify.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        return is_resource($process) ? proc_close($process) : throw new RuntimeException('cannot start candidate verifier');
    };
    if ($runCli() !== 0) {
        throw new RuntimeException('candidate verifier failed with matching fake gh response');
    }
    file_put_contents($directory.'/attestation.json', '[]');
    if ($runCli() === 0) {
        throw new RuntimeException('candidate verifier accepted absent attestation');
    }

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
    foreach ($files as $key => $name) {
        $path = $directory.'/'.$name;
        $original = (string) file_get_contents($path);
        $reject(static fn () => trustedProducerVerify($directory, $approved, static function () use ($path): bool {
            file_put_contents($path, 'replaced during verification');
            return true;
        }));
        file_put_contents($path, $original);
    }
    // Recompute approved hashes to show semantic validation rejects consistently
    // signed but false claims rather than merely catching a changed digest.
    foreach (['source_sha', 'split_sha', 'tag', 'run_id', 'run_attempt', 'schema'] as $claim) {
        $badMapping = $mapping;
        $badMapping[$claim] = 'wrong';
        file_put_contents($directory.'/'.$files['mapping_sha256'], json_encode($badMapping, JSON_THROW_ON_ERROR));
        $bad = $approved;
        $bad['mapping_sha256'] = hash_file('sha256', $directory.'/'.$files['mapping_sha256']);
        $reject(static fn () => trustedProducerVerify($directory, $bad, static fn (): bool => true));
    }
    file_put_contents($directory.'/'.$files['mapping_sha256'], json_encode($mapping, JSON_THROW_ON_ERROR));
    foreach (['source_sbom_sha256', 'dependency_sbom_sha256', 'lock_sha256'] as $key) {
        $path = $directory.'/'.$files[$key];
        $original = (string) file_get_contents($path);
        file_put_contents($path, '{}');
        $bad = $approved;
        $bad[$key] = hash_file('sha256', $path);
        $badMapping = $mapping;
        $badMapping['files'][$files[$key]] = $bad[$key];
        file_put_contents($directory.'/'.$files['mapping_sha256'], json_encode($badMapping, JSON_THROW_ON_ERROR));
        $bad['mapping_sha256'] = hash_file('sha256', $directory.'/'.$files['mapping_sha256']);
        $reject(static fn () => trustedProducerVerify($directory, $bad, static fn (): bool => true));
        file_put_contents($path, $original);
    }
    file_put_contents($directory.'/'.$files['mapping_sha256'], json_encode($mapping, JSON_THROW_ON_ERROR));
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
