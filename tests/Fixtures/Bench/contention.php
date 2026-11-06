<?php

declare(strict_types=1);

use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Profiles\LockContention;
use AzGuardBench\Load\Runner;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$work = $root.'/bench/.runs/contention-test-'.getmypid();

try {
    $stand = Stand::boot('sqlite', 'none', $work, false, true);
    set_exception_handler(static function (Throwable $error): never {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    });
    $stand->migrate();
    $tier = Tier::named('smoke');
    Dataset::seed($tier);
    $profile = new LockContention;
    $stage = new Stage('hot-subject:writers4:readers1', 5, 20, 5);
    $profile->prepare($stand, $tier, $stage);
    $result = (new Runner)->run($stand, $tier, $profile, $stage, $work.'/workers');
    $checks = $profile->verify($stand, $tier, $stage);
    $errors = array_filter($result['samples'], static fn (array $sample): bool => ! $sample['ok']);
    $operations = array_column($result['samples'], 'op');

    if ($errors !== [] || in_array(false, array_column($checks, 'ok'), true)
        || ! in_array('probe.lock_wait', $operations, true) || ! in_array('probe.lock_hold', $operations, true)) {
        throw new RuntimeException('Contention fixture failed: '.json_encode($checks));
    }
} finally {
    if (is_dir($work)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($work);
    }
}
