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
        $byId = array_column($result['profiles'], null, 'id');
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

        $churn = $byId['cache-churn']['stages'][0];
        $measurement = $churn['measurements'][0][0];
        expect($churn['iterations_per_worker'])->toBe(10)
            ->and($measurement['rss_peak_bytes'])->toBeGreaterThanOrEqual($measurement['rss_start_bytes'])
            ->and($measurement['max_request_sets'])->toBe(1024)
            ->and($measurement['max_request_states'])->toBe(1024);

        $branches = $byId['visibility-branches']['stages'];
        expect(array_column($branches, 'name'))->toBe(['branches-999', 'branches-1000', 'branches-10000'])
            ->and($branches[0]['summary'])->toHaveKey('visible')
            ->and($branches[1]['summary'])->toHaveKey('visible')
            ->and($branches[2]['summary'])->toHaveKey('overlimit');

        foreach ($byId['lock-contention']['stages'] as $stage) {
            expect($stage['summary'])->toHaveKeys(['probe.lock_wait', 'probe.lock_hold'])
                ->and($stage['measurements'][0][0])->toHaveKeys(['transaction_rollbacks', 'uncaught_deadlocks', 'uncaught_timeouts', 'retries', 'deadlocks', 'timeouts']);
        }

        foreach ($byId['filament-pagination']['stages'] as $stage) {
            expect($stage['summary'])->toHaveKeys(['count', 'page']);
        }
    } finally {
        @unlink($out.'.json');
        @unlink($out.'.md');
    }
});
