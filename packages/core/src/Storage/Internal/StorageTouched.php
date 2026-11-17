<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use Packman\Attributes\Internal;

/** @internal Published only after the root transaction commits. */
#[Internal]
final readonly class StorageTouched
{
    /** @param list<string> $panels */
    public function __construct(public array $panels) {}
}
