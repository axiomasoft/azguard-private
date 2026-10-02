<?php

declare(strict_types=1);

namespace AzGuard\Roles\Attributes;

use Attribute;

/**
 * Keys the role had before a rename.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FormerKeys
{
    /** @var list<string> */
    public array $keys;

    public function __construct(string ...$keys)
    {
        $this->keys = array_values($keys);
    }
}
