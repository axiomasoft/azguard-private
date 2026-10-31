<?php

declare(strict_types=1);

require __DIR__.'/verify.php';

if ($argc !== 5 || ! in_array($argv[1], ['http', 'file'], true) || ! ctype_digit($argv[3]) || (int) $argv[3] < 1) {
    fwrite(STDERR, "usage: php drive.php http|file URL|INPUT COUNT OUTPUT\n");
    exit(2);
}

try {
    $input = $argv[1] === 'file' ? fopen($argv[2], 'rb') : null;
    $output = fopen($argv[4], 'xb');

    if (($argv[1] === 'file' && $input === false) || $output === false) {
        throw new RuntimeException('cannot open series');
    }
    $pid = null;
    for ($number = 1; $number <= (int) $argv[3]; $number++) {
        $line = $input === null ? file_get_contents($argv[2].'/worker-probe/'.$number, false,
            stream_context_create(['http' => ['timeout' => 15, 'header' => "X-Worker-Marker: previous-request\r\n"]])) : fgets($input);

        if ($line === false) {
            throw new RuntimeException("request {$number}: missing response");
        }
        $sample = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        verifyWorkerSample($sample, $number, $pid);
        fwrite($output, json_encode($sample, JSON_THROW_ON_ERROR)."\n");
    }

    if ($input !== null && fgets($input) !== false) {
        throw new RuntimeException('unexpected additional jobs');
    }
    echo "verified {$argv[3]} {$argv[1]} lifecycles in pid {$pid}\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
