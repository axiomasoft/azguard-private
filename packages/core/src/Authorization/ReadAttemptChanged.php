<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use RuntimeException;

/** @internal Retry signal; its frame contains no accepted authority state. */
final class ReadAttemptChanged extends RuntimeException
{
    public function __construct(public readonly EvaluationFrame $frame)
    {
        parent::__construct('Dynamic Prepare/authority changed during the read attempt.');
    }
}
