<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Events\AccessEvent;

/**
 * One effect of a change as a `ChangeParticipant` sees it: the final, validated change, the effect and the event the
 * engine built for that effect once. The same event object is dispatched after the root commit, so a row written from
 * a record carries the id of the event listeners receive.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ChangeRecord
{
    /** @internal built by the write coordinator */
    public function __construct(
        public Change $change,
        public ChangeEffect $effect,
        public AccessEvent $event,
    ) {}
}
