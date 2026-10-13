<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Testing\Contracts\ChangeParticipantContractTests;
use Closure;
use PHPUnit\Framework\Assert;

/** @mixin Assert */
final class ParticipantProbe
{
    use ChangeParticipantContractTests;
    use RunsAsAProbe;

    /** @param Closure(): ChangeParticipant $make */
    public function __construct(private readonly Closure $make) {}

    protected function azguardParticipant(): ChangeParticipant
    {
        return ($this->make)();
    }
}
