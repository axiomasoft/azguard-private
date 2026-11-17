<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * One page of stored grants of a grant manager. `nextCursor` is null on the last page; otherwise
 * `$filter->after($page->nextCursor)` asks for the next one.
 */
#[Api(since: '1.0')]
final readonly class GrantPage
{
    /**
     * @param  list<GrantRecord>  $items
     */
    #[Internal('built by a grant manager')]
    public function __construct(public array $items, public ?string $nextCursor) {}
}
