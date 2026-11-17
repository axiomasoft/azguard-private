<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Events\AccessEvent;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * One effect of a change as a `ChangeParticipant` sees it: the final, validated change, the effect and the event the
 * engine built for that effect once. The same event object is dispatched after the root commit, so a row written from
 * a record carries the id of the event listeners receive.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ChangeRecord
{
    /*
 */
    #[Internal('built by the write coordinator')]
    public function __construct(
        public Change $change,
        public ChangeEffect $effect,
        public AccessEvent $event,
    ) {}
}
