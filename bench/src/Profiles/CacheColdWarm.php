<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Support\Facades\Cache;

/**
 * Cache cold and warm. In triples for one random user: `cold` flushes the persistent store (when the panel has one)
 * and starts a new request; `warm_store` starts a new request with the store primed by `cold`; `warm_request` asks
 * again in the same request. With `--cache=none` the two first are the same read from storage, which is the point of
 * comparing the runs. A second stage runs only `warm_store` with every worker sharing the store (redis).
 */
final class CacheColdWarm extends BaseProfile
{
    private int $user = 2;

    private string $permission = 'g1.p1';

    public function id(): string
    {
        return 'cache-cold-warm';
    }

    public function description(): string
    {
        return 'First check with a flushed store, with a primed store, and in the same request';
    }

    public function stages(Tier $tier): array
    {
        return [
            new Stage('coldwarm:w1', 1, $tier->iterations - $tier->iterations % 3, $tier->warmup - $tier->warmup % 3),
            new Stage('warm:w'.$tier->maxWorkers(), $tier->maxWorkers(), $tier->iterations, $tier->warmup),
        ];
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        if ($stage->workers > 1) {
            $stand->newRequest();
            $this->check($this->subject($this->randomUser($tier)), $this->randomPermission());

            return 'warm_store';
        }

        $op = ['cold', 'warm_store', 'warm_request'][$i % 3];

        if ($op === 'cold') {
            $this->user = $this->randomUser($tier);
            $this->permission = $this->randomPermission();

            if ($stand->cache !== 'none') {
                Cache::store($stand->cache)->flush();
            }
        }

        if ($op !== 'warm_request') {
            $stand->newRequest();
        }
        $this->check($this->subject($this->user), $this->permission);

        return $op;
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        if ($stage->workers > 1 && $stand->cache !== 'none') {
            // The shared stage starts primed: one pass over every user fills the store.
            for ($user = 2; $user <= $tier->users; $user++) {
                $stand->newRequest();
                $this->check($this->subject($user), 'g1.p1');
            }
        }
    }
}
