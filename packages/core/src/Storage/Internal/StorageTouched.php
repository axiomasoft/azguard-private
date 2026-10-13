<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

/** @internal Published only after the root transaction commits. */
final readonly class StorageTouched
{
    /** @param list<string> $panels */
    public function __construct(public array $panels) {}
}
