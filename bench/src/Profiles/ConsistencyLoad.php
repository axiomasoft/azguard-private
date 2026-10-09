<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Changes\ChangeResult;
use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Consistency under writes (audits/2026-10-09-consistency-design.md, step 0). Worker 0 writes, the other workers check;
 * every check is a new request. Stages:
 *
 * - `disjoint`: the writer toggles a grant on pool A, readers check only pool B. Nothing a reader reads changes, so
 *   any `check_inconsistent` is a false conflict of the panel-wide version.
 * - `hot`: writer and readers work on one subject.
 * - `paced50`/`paced200`: an open-loop writer (a fixed rate of writes per second, whatever the latency) on pool A,
 *   readers on pools A and B.
 *
 * A check is `check_hit` when it read no grant table (the permission set came from the cache), `check` otherwise, and
 * `check_inconsistent` when the engine denied it with consistency_error; the hit ratio under writes is
 * check_hit / (check + check_hit).
 */
final class ConsistencyLoad extends BaseProfile
{
    private int $grantReads = 0;

    public function id(): string
    {
        return 'consistency-load';
    }

    public function description(): string
    {
        return 'Readers on other subjects, a hot subject and an open-loop writer: false conflicts and cache hits';
    }

    public function stages(Tier $tier): array
    {
        $workers = $tier->maxWorkers();
        $iterations = max(20, intdiv($tier->iterations, 2));

        return array_map(static fn (string $name): Stage => new Stage($name.':w'.$workers, $workers, $iterations, $tier->warmup),
            ['disjoint', 'hot', 'paced50', 'paced200']);
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        AzGuard::actingAs('benchmark', function () use ($tier, $stand): void {
            foreach (array_merge(...$this->pools($tier)) as $user) {
                $stand->newRequest();
                $subject = $this->subject($user);

                if ($this->check($subject, Catalog::missingPermission())) {
                    $subject->revokePermission(Catalog::missingPermission());
                }
            }
        });
    }

    public function boot(Stand $stand, Tier $tier, Stage $stage, int $worker): void
    {
        DB::listen(function (QueryExecuted $event): void {
            if (str_contains($event->sql, '_grants') && str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $this->grantReads++;
            }
        });
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        [$writes, $reads] = $this->targets($tier, $stage);
        $stand->newRequest();

        if ($worker === 0) {
            $this->pace($stage, $i);
            $subject = $this->subject($writes[$i % count($writes)]);
            $grant = intdiv($i, count($writes)) % 2 === 0;
            AzGuard::actingAs('benchmark', static fn (): ChangeResult => $grant
                ? $subject->grantPermission(Catalog::missingPermission())
                : $subject->revokePermission(Catalog::missingPermission()));

            return 'write';
        }
        $before = $this->grantReads;
        $decision = $this->subject($reads[mt_rand(0, count($reads) - 1)])->decide(Catalog::missingPermission());

        return match ($decision->reason->value) {
            'consistency_error' => 'check_inconsistent',
            'granted', 'not_granted' => $this->grantReads === $before ? 'check_hit' : 'check',
            default => throw new RuntimeException('Check failed: '.$decision->reason->value),
        };
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        [$writes] = $this->targets($tier, $stage);
        $done = $stage->warmup + $stage->iterations;
        $wrong = 0;
        foreach ($writes as $index => $user) {
            $toggles = intdiv($done, count($writes)) + ($index < $done % count($writes) ? 1 : 0);
            $stand->newRequest();
            $wrong += $this->check($this->subject($user), Catalog::missingPermission()) === ($toggles % 2 === 1) ? 0 : 1;
        }

        return [['name' => 'every written subject ends in the state of its last write', 'ok' => $wrong === 0, 'detail' => "{$wrong} of ".count($writes).' wrong']];
    }

    /** An open-loop writer sleeps until the scheduled start of write $i; a closed-loop one writes at once. */
    private function pace(Stage $stage, int $i): void
    {
        static $start = null;
        $rate = match (true) {
            str_starts_with($stage->name, 'paced50') => 50,
            str_starts_with($stage->name, 'paced200') => 200,
            default => 0,
        };

        if ($rate === 0) {
            return;
        }
        $start = is_float($start) ? $start : microtime(true);
        $wait = $start + $i / $rate - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    /** @return array{list<int>, list<int>} the subjects the writer toggles and the subjects readers check */
    private function targets(Tier $tier, Stage $stage): array
    {
        [$a, $b] = $this->pools($tier);

        return match (strstr($stage->name, ':', true)) {
            'disjoint' => [$a, $b],
            'hot' => [[$a[0]], [$a[0]]],
            default => [$a, array_merge($a, $b)],
        };
    }

    /** @return array{list<int>, list<int>} two disjoint pools of light users */
    private function pools(Tier $tier): array
    {
        $size = min(50, intdiv($tier->users - 1, 2));

        return [range(2, 1 + $size), range(2 + $size, 1 + 2 * $size)];
    }
}
