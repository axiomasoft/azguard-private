<?php

declare(strict_types=1);

use AzGuardBench\Load\Profiles\BaseProfile;
use AzGuardBench\Load\Runner;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$work = $root.'/bench/.runs/warmup-test-'.getmypid();

try {
    $stand = Stand::boot('sqlite', 'none', $work);
    set_exception_handler(static function (Throwable $error): never {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    });
    $profile = new class extends BaseProfile
    {
        public function id(): string
        {
            return 'warmup-failure';
        }

        public function description(): string
        {
            return 'Synthetic failed warmup';
        }

        public function stages(Tier $tier): array
        {
            return [];
        }

        public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
        {
            throw new RuntimeException('synthetic warmup failure');
        }
    };
    $failed = false;

    try {
        (new Runner)->run($stand, Tier::named('smoke'), $profile, new Stage('warmup', 1, 1, 1), $work.'/workers');
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'crashed');
    }

    if (! $failed) {
        fwrite(STDERR, "A failed warm-up was accepted.\n");
        exit(1);
    }
} finally {
    if (is_dir($work)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($work);
    }
}
