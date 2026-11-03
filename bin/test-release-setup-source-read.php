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
if [[ "$2" == repos/axiomasoft/azguard-private ]]; then
  if [[ "${FIXTURE_BAD_VISIBILITY:-0}" == 1 ]]; then printf 'invalid\n'; exit 0; fi
  if [[ "${FIXTURE_FLIP_VISIBILITY:-0}" == 1 ]] && [[ "$(grep -cFx "$2" "$FIXTURE_CALLS")" == 2 ]]; then printf 'false\n'; exit 0; fi
  if [[ "${FIXTURE_PUBLIC_SOURCE:-0}" == 1 ]]; then printf 'false\n'; exit 0; fi
  printf 'true\n'; exit 0
fi
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
        foreach (['success', 'denied', 'invalid', 'missing', 'invalid-token', 'public', 'bad-visibility', 'visibility-changed'] as $scenario) {
            $calls = $dir.'/calls';
            file_put_contents($calls, '');
            $environment = array_merge($_ENV, [
                'PATH' => $dir.':'.getenv('PATH'),
                'GH_TOKEN' => match ($scenario) {
                    'missing' => '',
                    'invalid-token' => 'invalid-token',
                    default => 'fixture-token',
                },
                'FIXTURE_CALLS' => $calls,
                'FIXTURE_DENY_CONTENTS' => $scenario === 'denied' ? '1' : '0',
                'FIXTURE_BAD_CONTENTS' => $scenario === 'invalid' ? '1' : '0',
                'FIXTURE_PUBLIC_SOURCE' => $scenario === 'public' ? '1' : '0',
                'FIXTURE_BAD_VISIBILITY' => $scenario === 'bad-visibility' ? '1' : '0',
                'FIXTURE_FLIP_VISIBILITY' => $scenario === 'visibility-changed' ? '1' : '0',
                'GITHUB_OUTPUT' => $dir.'/output',
            ]);
            $process = proc_open(['bash', '-c', $step['run']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start source probe');
            }
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if (($exit === 0) !== in_array($scenario, ['success', 'public'], true)) {
                throw new RuntimeException($package.' '.$scenario.' returned '.$exit.': '.$output);
            }
            if (in_array($scenario, ['success', 'public'], true)) {
                $actual = file($calls, FILE_IGNORE_NEW_LINES);
                $expected = [
                    'repos/axiomasoft/azguard-private',
                    'repos/axiomasoft/azguard-private/actions/runs?per_page=1',
                    'repos/axiomasoft/azguard-private/contents/packages/core/composer.json',
                    'repos/axiomasoft/azguard-private',
                ];
                if ($actual !== $expected) {
                    throw new RuntimeException($package.' '.$scenario.' did not check all three APIs');
                }
                $outputFile = file_get_contents($dir.'/output');
                if (! str_ends_with((string) $outputFile, 'private='.($scenario === 'public' ? 'false' : 'true')."\n")) {
                    throw new RuntimeException($package.' recorded the wrong visibility');
                }
            }
        }
    }
} finally {
    @unlink($dir.'/calls');
    @unlink($dir.'/gh');
    @unlink($dir.'/output');
    rmdir($dir);
}
echo "Both source read probes support public and private sources, and reject missing token, denied Contents and invalid responses.\n";
