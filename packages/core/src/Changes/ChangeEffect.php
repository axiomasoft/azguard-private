<?php

declare(strict_types=1);

namespace AzGuard\Changes;

/**
 * What the writer did to one stored grant, with the grant before and after. `eventId` identifies the effect for
 * delivery after commit.
 *
 * @api
 */
final readonly class ChangeEffect
{
    /** @internal built by the writer */
    public function __construct(
        public EffectKind $kind,
        public ChangeType $type,
        public ?GrantRecord $before,
        public ?GrantRecord $after,
        public string $eventId,
    ) {}
}
