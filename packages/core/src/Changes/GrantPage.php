<?php

declare(strict_types=1);

namespace AzGuard\Changes;

/**
 * One page of stored grants of a grant manager. `nextCursor` is null on the last page; otherwise
 * `$filter->after($page->nextCursor)` asks for the next one.
 *
 * @api
 */
final readonly class GrantPage
{
    /**
     * @internal built by a grant manager
     *
     * @param  list<GrantRecord>  $items
     */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
