<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use Throwable;

/**
 * A `changing` pipe cancelled the change; the whole operation is rolled back.
 */
final class ChangeCancelledException extends ChangeException
{
    public function __construct(string $message = '', private readonly ?string $reason = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function because(string $reason): self
    {
        return new self('Change cancelled: '.$reason, $reason);
    }

    public function code(): string
    {
        return 'change_cancelled';
    }

    /** The reason a pipe gave, or null when none was given. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
