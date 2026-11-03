<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-core-producer-candidate.php';

// Offline candidate interface only. The caller must authenticate every identity,
// digest and tool against protected configuration before invoking this script.
try {
    if ($argc !== 3 || ! in_array($argv[1], ['build', 'verify'], true) || is_link($argv[2]) || ! is_file($argv[2])) {
        throw new RuntimeException('usage: trusted-producer-pipeline.php build|verify approved.json');
    }
    $config = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($config)) {
        throw new RuntimeException('invalid independently approved input');
    }
    if ($argv[1] === 'build') {
        $required = ['repository', 'identity', 'inputs', 'output', 'source_run', 'approved_output', 'predicate_output'];
        $actual = array_keys($config);
        sort($actual);
        sort($required);
        if ($actual !== $required || ! is_array($config['identity']) || ! is_array($config['inputs']) || ! is_array($config['source_run'])) {
            throw new RuntimeException('incomplete build configuration');
        }
        foreach (['repository', 'output', 'approved_output', 'predicate_output'] as $key) {
            if (! is_string($config[$key]) || ! str_starts_with($config[$key], '/') || (! in_array($key, ['repository', 'output'], true) && (file_exists($config[$key]) || is_link($config[$key])))) {
                throw new RuntimeException('unsafe build path');
            }
        }
        $approvedParent = realpath(dirname($config['approved_output']));
        $predicateParent = realpath(dirname($config['predicate_output']));
        if ($approvedParent === false || $predicateParent === false
            || ($approvedParent === $predicateParent && basename($config['approved_output']) === basename($config['predicate_output']))) {
            throw new RuntimeException('evidence output paths must differ');
        }
        $result = trustedProducerBuildOffline($config['repository'], $config['identity'], $config['inputs'], $config['output'], $config['source_run']);
        $predicate = (array) $result['predicate'];
        unset($predicate['schema'], $predicate['producer_event']);
        foreach ([$config['approved_output'] => $predicate, $config['predicate_output'] => $result['predicate']] as $path => $document) {
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                throw new RuntimeException('cannot exclusively create evidence input');
            }
            try {
                $json = json_encode($document, JSON_THROW_ON_ERROR);
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new RuntimeException('cannot write evidence input');
                }
            } finally {
                fclose($handle);
            }
        }
    } else {
        if (array_keys($config) !== ['directory', 'approved'] || ! is_string($config['directory']) || ! is_array($config['approved'])) {
            throw new RuntimeException('invalid verification configuration');
        }
        trustedProducerVerify($config['directory'], $config['approved'], static function (array $command, array $predicates): bool {
            try {
                return packageAttestationPredicatesMatch(packageEvidenceCommand($command), $predicates);
            } catch (Throwable) {
                return false;
            }
        });
    }
} catch (Throwable $error) {
    fwrite(STDERR, "trusted producer pipeline failed: ".$error->getMessage()."\n");
    exit(1);
}
