<?php

declare(strict_types=1);

// Invoked only by the candidate with an allowlisted environment. The repository
// path must be a newly initialized repository controlled by the caller.
require_once __DIR__.'/verify-package-attestations.php';

if (count($argv) !== 4) {
    fwrite(STDERR, "invalid arguments\n");
    exit(1);
}
try {
    packageVerifySignedTag($argv[1], $argv[2], $argv[3]);
} catch (Throwable $error) {
    fwrite(STDERR, "isolated tag verification failed\n");
    exit(1);
}
