<?php

declare(strict_types=1);

// The load bench (bench/) is not a gate of speed, but it must keep working: the smoke tier runs every profile with
// forked workers in seconds, and every correctness check of its stages must pass.
it('runs every load profile of the smoke tier with passing checks', function (): void {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('The load bench needs the pcntl extension.');
    }
    $out = sys_get_temp_dir().'/azguard-bench-smoke-'.getmypid();
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg($root.'/bench/bin/run.php').' --tier=smoke --out='.escapeshellarg($out).' 2>&1', $output, $exit);

    try {
        expect($exit)->toBe(0, implode("\n", $output));
        $result = json_decode((string) file_get_contents($out.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $profiles = json_decode((string) file_get_contents($root.'/bench/profiles.json'), true, flags: JSON_THROW_ON_ERROR)['profiles'];
        $checks = $probes = [];
        foreach ($result['profiles'] as $profile) {
            foreach ($profile['stages'] as $stage) {
                $probes = [...$probes, ...array_filter(array_keys($stage['summary']), static fn (string $op): bool => str_starts_with($op, 'probe.'))];
                foreach ($stage['checks'] as $check) {
                    $checks[] = $check['ok'];
                }
            }
        }

        expect($result['schema'])->toBe('urn:azguard:bench:result:1')
            ->and(array_column($result['profiles'], 'id'))->toBe(array_column($profiles, 'id'))
            ->and($checks)->not->toBeEmpty()->not->toContain(false)
            // The bench-side probes see snapshot reads and the panel lock of writes.
            ->and(array_values(array_unique($probes)))->toContain('probe.snapshot', 'probe.lock_wait', 'probe.lock_hold');
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});
