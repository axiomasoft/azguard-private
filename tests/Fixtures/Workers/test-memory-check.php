<?php

declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'azguard-worker-series-');

if ($path === false) {
    throw new RuntimeException('Cannot create temporary series');
}

try {
    $run = static function (string $case) use ($path): int {
        $stream = fopen($path, 'wb');
        $measured = in_array($case, ['early-plateau', 'early-peak'], true) ? 10000 : 5000;
        for ($i = 1; $i <= 200 + $measured; $i++) {
            $sample = ['request' => $i, 'pid' => 123, 'rss' => 100 * 1024 * 1024,
                'counts' => ['memo' => 0, 'states' => 0, 'panels' => 0]];

            if ($i === 5200) {
                match ($case) {
                    'pid' => $sample['pid'] = 124,
                    'state' => $sample['counts']['states'] = 1,
                    'rss' => $sample['rss'] += 11 * 1024 * 1024,
                    'sequence' => $sample['request'] = 1,
                    default => null,
                };
            }

            if (($case === 'early-plateau' && $i > 200) || ($case === 'early-peak' && $i === 201)) {
                $sample['rss'] += 11 * 1024 * 1024;
            }
            fwrite($stream, json_encode($sample, JSON_THROW_ON_ERROR)."\n");
        }
        fclose($stream);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/check-memory.php').' '.escapeshellarg($path).' '.$measured;
        exec($command.' 2>&1', $output, $exit);

        return $exit;
    };
    foreach (['valid' => 0, 'pid' => 1, 'state' => 1, 'rss' => 1, 'sequence' => 1, 'early-plateau' => 1, 'early-peak' => 1] as $case => $expected) {
        if ($run($case) !== $expected) {
            throw new RuntimeException("Unexpected result for {$case}");
        }
    }
    echo "memory checker fixtures passed\n";
} finally {
    unlink($path);
}
