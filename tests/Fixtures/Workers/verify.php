<?php

declare(strict_types=1);

function verifyWorkerSample(array $sample, int $number, ?int &$pid): void
{
    $case = $number % 5;
    $expected = [
        'request' => $number,
        'counts' => ['memo' => 0, 'states' => 0, 'panels' => 0],
        'allowed' => $case === 0,
        'reason' => ['granted', 'not_granted', 'not_granted', 'source_error', 'hook_error'][$case],
        'tenant' => $case === 1 ? 'B' : 'A',
        'panel' => $number % 2 ? 'worker-a' : 'worker-b',
        'subject' => [1, 1, 2, 3, 4][$case],
        'actor' => (string) (1000 + $number),
    ];
    foreach ($expected as $key => $value) {
        if (! array_key_exists($key, $sample) || $sample[$key] !== $value) {
            throw new RuntimeException("request {$number}: unexpected {$key}");
        }
    }

    if (! is_int($sample['pid'] ?? null) || $sample['pid'] < 1 || ! is_int($sample['rss'] ?? null) || $sample['rss'] < 1) {
        throw new RuntimeException("request {$number}: invalid process sample");
    }

    if (! is_int($sample['memo_after'] ?? null) || $sample['memo_after'] < 0 || ($case === 0 && $sample['memo_after'] === 0)) {
        throw new RuntimeException("request {$number}: memo was not exercised");
    }
    $pid ??= $sample['pid'];

    if ($pid !== $sample['pid']) {
        throw new RuntimeException("request {$number}: worker recycled");
    }
}
