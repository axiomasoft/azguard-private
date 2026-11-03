<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$dir = sys_get_temp_dir().'/azguard-source-probe-'.bin2hex(random_bytes(8));
if (! mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create probe fixture directory');
}

try {
    $gh = <<<'SH'
#!/usr/bin/env bash
[[ "$GH_TOKEN" == fixture-token && "$1" == api ]] || exit 1
printf '%s\n' "$2" >> "$FIXTURE_CALLS"
if [[ "$2" == repos/axiomasoft/azguard-private && "${FIXTURE_PUBLIC_SOURCE:-0}" == 1 ]]; then printf 'false\n'; exit 0; fi
if [[ "$2" == */contents/* && "${FIXTURE_DENY_CONTENTS:-0}" == 1 ]]; then exit 1; fi
if [[ "$2" == */contents/* && "${FIXTURE_BAD_CONTENTS:-0}" == 1 ]]; then printf 'false\n'; exit 0; fi
printf 'true\n'
SH;
    file_put_contents($dir.'/gh', $gh."\n");
    chmod($dir.'/gh', 0700);
    foreach (['core', 'filament'] as $package) {
        $workflow = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__).'/packages/'.$package.'/.github/workflows/attestation-setup.yml');
        $step = $workflow['jobs']['probe']['steps'][0];
        if (($step['env']['GH_TOKEN'] ?? null) !== '${{ secrets.PRIVATE_RELEASE_READ_TOKEN }}') {
            throw new RuntimeException($package.' probe does not use the source read secret');
        }
        foreach (['success', 'denied', 'invalid', 'missing', 'public'] as $scenario) {
            $calls = $dir.'/calls';
            file_put_contents($calls, '');
            $environment = array_merge($_ENV, [
                'PATH' => $dir.':'.getenv('PATH'),
                'GH_TOKEN' => $scenario === 'missing' ? '' : 'fixture-token',
                'FIXTURE_CALLS' => $calls,
                'FIXTURE_DENY_CONTENTS' => $scenario === 'denied' ? '1' : '0',
                'FIXTURE_BAD_CONTENTS' => $scenario === 'invalid' ? '1' : '0',
                'FIXTURE_PUBLIC_SOURCE' => $scenario === 'public' ? '1' : '0',
            ]);
            $process = proc_open(['bash', '-c', $step['run']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start source probe');
            }
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if (($exit === 0) !== ($scenario === 'success')) {
                throw new RuntimeException($package.' '.$scenario.' returned '.$exit.': '.$output);
            }
            if ($scenario !== 'missing' && $scenario !== 'public') {
                $actual = file($calls, FILE_IGNORE_NEW_LINES);
                $expected = [
                    'repos/axiomasoft/azguard-private',
                    'repos/axiomasoft/azguard-private/actions/runs?per_page=1',
                    'repos/axiomasoft/azguard-private/contents/packages/core/composer.json',
                ];
                if ($actual !== $expected) {
                    throw new RuntimeException($package.' '.$scenario.' did not check all three APIs');
                }
            }
        }
    }
} finally {
    @unlink($dir.'/calls');
    @unlink($dir.'/gh');
    rmdir($dir);
}
echo "Both source read probes reject public source, missing token, denied and invalid Contents access.\n";
