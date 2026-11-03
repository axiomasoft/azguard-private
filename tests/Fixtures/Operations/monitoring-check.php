<?php

declare(strict_types=1);

// Read one Prometheus instant-query response; require a finite positive sample.
// A 200 response with an error, an empty vector or a zero is not a firing signal.
try {
    $response = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $result = $response['data']['result'] ?? null;

    if (($response['status'] ?? null) !== 'success' || ($response['data']['resultType'] ?? null) !== 'vector'
        || ! is_array($result) || count($result) !== 1) {
        throw new RuntimeException('Missing monitoring signal.');
    }
    $value = $result[0]['value'][1] ?? null;

    if (! is_string($value) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
        throw new RuntimeException('Monitoring signal did not fire.');
    }
    echo "Monitoring signal observed.\n";
} catch (Throwable) {
    fwrite(STDERR, "Monitoring acceptance failed; inspect the protected monitoring evidence.\n");
    exit(1);
}
