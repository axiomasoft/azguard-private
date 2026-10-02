<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Sources\Source;

final readonly class ArraySource implements Source
{
    public function __construct(private string $id = 'array') {}

    public function id(): string
    {
        return $this->id;
    }
}
