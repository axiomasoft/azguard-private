<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;
use AzGuard\Attributes\Api;

/**
 * Keys the role had before a rename.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0')]
final readonly class FormerKeys
{
    /** @var list<string> */
    public array $keys;

    public function __construct(string ...$keys)
    {
        $this->keys = array_values($keys);
    }
}
