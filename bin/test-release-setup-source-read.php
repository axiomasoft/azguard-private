<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

foreach (['core', 'filament'] as $package) {
    $path = dirname(__DIR__).'/packages/'.$package.'/.github/workflows/attestation-setup.yml';
    $raw = file_get_contents($path);
    $workflow = Symfony\Component\Yaml\Yaml::parseFile($path);
    if (! is_string($raw) || str_contains($raw, 'secrets.') || str_contains($raw, 'PRIVATE_RELEASE_READ_TOKEN')
        || ($workflow['permissions'] ?? null) !== ['contents' => 'read']
        || count($workflow['jobs']['probe']['steps'] ?? []) !== 1) {
        throw new RuntimeException($package.' disabled setup workflow exposes secret or extra authority');
    }
    $script = $workflow['jobs']['probe']['steps'][0]['run'] ?? null;
    if (! is_string($script)) {
        throw new RuntimeException($package.' disabled setup workflow is missing its guard');
    }
    $process = proc_open(['bash', '-c', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process) || proc_close($process) === 0) {
        throw new RuntimeException($package.' disabled setup workflow succeeded');
    }
}
echo "Both split setup workflows are disabled and do not expose secrets.\n";
