<?php

declare(strict_types=1);

// The producer writes one JSON object per request, including the 200 warmup requests.
// RSS is in bytes; counts describe fresh lifecycle-scoped services before the request work.
if ($argc !== 3 || ! ctype_digit($argv[2]) || (int) $argv[2] < 5000) {
    fwrite(STDERR, "usage: php check-memory.php SERIES.jsonl MEASURED_REQUESTS (at least 5000)\n");
    exit(2);
}

try {
    $file = new SplFileObject($argv[1]);
    $samples = [];
    foreach ($file as $line) {
        if (trim($line) !== '') {
            $samples[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
    }
    $measured = (int) $argv[2];

    if (count($samples) !== 200 + $measured) {
        throw new RuntimeException('incomplete request series');
    }
    $pid = $samples[0]['pid'] ?? null;
    $baseline = $samples[0]['counts'] ?? null;

    if (! is_int($pid) || $pid < 1 || ! is_array($baseline) || $baseline !== ['memo' => 0, 'states' => 0, 'panels' => 0]) {
        throw new RuntimeException('invalid worker or lifecycle baseline');
    }
    foreach ($samples as $index => $sample) {
        if (! is_array($sample) || ($sample['request'] ?? null) !== $index + 1 || ($sample['pid'] ?? null) !== $pid
            || ($sample['counts'] ?? null) !== $baseline || ! is_int($sample['rss'] ?? null) || $sample['rss'] < 1) {
            throw new RuntimeException('worker changed, lifecycle state retained, or invalid sample at request '.($index + 1));
        }
    }
    $warmed = $samples[199]['rss'];
    $start = count($samples) - 5001;
    $lastWindow = array_column(array_slice($samples, $start + 1), 'rss');
    $growth = max($lastWindow) - $samples[$start]['rss'];
    $limit = max(8 * 1024 * 1024, (int) ceil($warmed * 0.10));

    if ($growth > $limit) {
        throw new RuntimeException("RSS growth {$growth} exceeds {$limit} bytes");
    }
    echo json_encode(['requests' => count($samples), 'warmup' => 200, 'pid' => $pid, 'warmed_rss' => $warmed,
        'window_start_rss' => $samples[$start]['rss'], 'window_peak_rss' => max($lastWindow), 'growth' => $growth, 'limit' => $limit], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
