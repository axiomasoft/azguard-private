<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;

/**
 * Concurrent permission checks: each iteration is a new request of a random light user asking for a random
 * permission, the first check of that request (permission set read from storage, or from the store when one is set).
 * The ladder of worker counts shows how latency and throughput scale with concurrent readers.
 */
final class ConcurrentChecks extends BaseProfile
{
    public function id(): string
    {
        return 'checks-concurrent';
    }

    public function description(): string
    {
        return 'First check of a request, random user and permission, 1..N concurrent workers';
    }

    public function stages(Tier $tier): array
    {
        return $this->ladder($tier, 'check');
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $stand->newRequest();
        $this->check($this->subject($this->randomUser($tier)), $this->randomPermission());

        return 'check';
    }
}
