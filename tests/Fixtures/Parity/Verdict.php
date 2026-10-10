<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

/** The answer of one path for one case: allowed or not, with the path's own explanation for a failure message. */
final readonly class Verdict
{
    public function __construct(public bool $allowed, public string $detail = '') {}

    public function label(): string
    {
        return ($this->allowed ? 'allow' : 'deny').($this->detail === '' ? '' : '('.$this->detail.')');
    }
}
