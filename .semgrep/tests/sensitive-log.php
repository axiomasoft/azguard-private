<?php

function sensitiveLog($grant, $subject, $roleMeta, $panel, $error): void
{
    // ruleid: azguard.sensitive-log
    Log::warning('Grant failed.', ['grant' => $grant]);

    // ruleid: azguard.sensitive-log
    Log::info('Resolved.', ['who' => $subject]);

    // ruleid: azguard.sensitive-log
    logger('Meta', $roleMeta);

    // ok: azguard.sensitive-log
    Log::warning('Evaluation failed.', ['panel' => $panel, 'exception' => $error::class]);

    // ok: azguard.sensitive-log
    logger('Nothing sensitive here.');
}
