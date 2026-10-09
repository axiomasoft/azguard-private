<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;

/**
 * Large role and permission sets: the heavy subject holds many roles of 50 permissions and many direct grants over
 * a catalog of 1 000 permissions. Operations rotate: a miss (the worst case, nothing matches), a hit, 50 abilities at
 * once and the whole permission set, each in a new request, plus a check repeated in the same request.
 */
final class LargeSets extends BaseProfile
{
    private const array OPS = ['miss', 'hit', 'abilities50', 'permission_set', 'same_request'];

    public function id(): string
    {
        return 'large-sets';
    }

    public function description(): string
    {
        return 'Heavy subject: many roles and direct grants over 1 000 permissions';
    }

    public function stages(Tier $tier): array
    {
        // A permission set of the heavy subject takes seconds (see the results): a tenth of the iterations is enough.
        $iterations = max(10, intdiv($tier->iterations, 10));
        $warmup = max(5, intdiv($tier->warmup, 4));

        return [new Stage('heavy:w1', 1, $iterations, $warmup), new Stage('heavy:w'.$tier->maxWorkers(), $tier->maxWorkers(), $iterations, $warmup)];
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $op = self::OPS[$i % count(self::OPS)];
        $heavy = $this->subject(Dataset::HEAVY);

        if ($op !== 'same_request') {
            $stand->newRequest();
        }
        match ($op) {
            'miss', 'same_request' => $this->check($heavy, Catalog::missingPermission()),
            'hit' => $this->check($heavy, Catalog::rolePermissions(1)[$i % Catalog::ROLE_SIZE]),
            'abilities50' => $heavy->abilities(Catalog::rolePermissions(2)),
            'permission_set' => $heavy->permissionSet(),
        };

        return $op;
    }
}
