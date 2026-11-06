<?php

declare(strict_types=1);

require __DIR__.'/verify.php';

$sample = ['request' => 5, 'counts' => ['memo' => 0, 'states' => 0, 'panels' => 0], 'allowed' => true,
    'reason' => 'granted', 'tenant' => 'A', 'panel' => 'worker-a', 'subject' => 1, 'actor' => '1005',
    'pid' => 123, 'rss' => 100, 'memo_after' => 1];
$pid = null;
verifyWorkerSample($sample, 5, $pid);
foreach (['allowed' => false, 'reason' => 'not_granted', 'tenant' => 'B', 'panel' => 'worker-b',
    'subject' => 2, 'actor' => '1006', 'pid' => 124, 'memo_after' => 0, 'counts' => ['memo' => 1, 'states' => 0, 'panels' => 0]] as $key => $wrong) {
    $invalid = $sample;
    $invalid[$key] = $wrong;

    try {
        verifyWorkerSample($invalid, 5, $pid);
    } catch (RuntimeException) {
        continue;
    }

    throw new RuntimeException("Accepted invalid {$key}");
}
echo "worker outcome fixtures passed\n";
