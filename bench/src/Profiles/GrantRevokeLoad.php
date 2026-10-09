<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Changes\ChangeResult;
use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use RuntimeException;

/**
 * Grant and revoke under load. Writers (one, or two from four workers) toggle a direct grant of a permission that
 * nobody else holds on their own pool of subjects; the other workers check that permission for subjects of the pools,
 * each check in a new request. After the stage every subject must hold the grant exactly when its writer granted it
 * last: writes are not lost and checks see committed state, whatever the interleaving. A check that the engine
 * could not read at one version three times in a row is counted as `check_inconsistent` (a fail-closed denial).
 */
final class GrantRevokeLoad extends BaseProfile
{
    public function id(): string
    {
        return 'grant-revoke-load';
    }

    public function description(): string
    {
        return 'Writers grant and revoke while readers check the same subjects';
    }

    public function stages(Tier $tier): array
    {
        $workers = array_values(array_filter($tier->ladder, static fn (int $w): bool => $w >= 2));

        return array_map(static fn (int $w): Stage => new Stage('writes:w'.$w, $w, max(20, intdiv($tier->iterations, 2)), $tier->warmup), $workers);
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        AzGuard::actingAs('benchmark', function () use ($tier, $stage, $stand): void {
            foreach ($this->pools($tier, $stage) as $pool) {
                foreach ($pool as $user) {
                    $stand->newRequest();
                    $subject = $this->subject($user);

                    if ($this->check($subject, Catalog::missingPermission())) {
                        $subject->revokePermission(Catalog::missingPermission());
                    }
                }
            }
        });
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $pools = $this->pools($tier, $stage);
        $stand->newRequest();

        if (isset($pools[$worker])) {
            $pool = $pools[$worker];
            $subject = $this->subject($pool[$i % count($pool)]);
            $grant = intdiv($i, count($pool)) % 2 === 0;
            AzGuard::actingAs('benchmark', static fn (): ChangeResult => $grant
                ? $subject->grantPermission(Catalog::missingPermission())
                : $subject->revokePermission(Catalog::missingPermission()));

            return $grant ? 'grant' : 'revoke';
        }
        $pool = $pools[mt_rand(0, count($pools) - 1)];
        $decision = $this->subject($pool[mt_rand(0, count($pool) - 1)])->decide(Catalog::missingPermission());

        // The engine retries a read three times when the panel state moves under it, then denies with
        // consistency_error (fail closed). Under constant writes that is an outcome to count, not a harness error.
        return match ($decision->reason->value) {
            'consistency_error' => 'check_inconsistent',
            'granted', 'not_granted' => 'check_under_writes',
            default => throw new RuntimeException('Check failed: '.$decision->reason->value),
        };
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $wrong = 0;
        $total = 0;
        $done = $stage->warmup + $stage->iterations;
        foreach ($this->pools($tier, $stage) as $pool) {
            foreach ($pool as $index => $user) {
                // Toggles of this subject: grant first, then revoke, alternating.
                $toggles = intdiv($done, count($pool)) + ($index < $done % count($pool) ? 1 : 0);
                $stand->newRequest();
                $wrong += $this->check($this->subject($user), Catalog::missingPermission()) === ($toggles % 2 === 1) ? 0 : 1;
                $total++;
            }
        }

        return [['name' => 'every subject ends in the state of its last write', 'ok' => $wrong === 0, 'detail' => "{$wrong} of {$total} wrong"]];
    }

    /** @return list<list<int>> the subjects of each writer, disjoint */
    private function pools(Tier $tier, Stage $stage): array
    {
        $writers = $stage->workers >= 4 ? 2 : 1;
        $size = min(50, intdiv($tier->users - 1, $writers));
        $pools = [];
        for ($w = 0; $w < $writers; $w++) {
            $pools[] = range(2 + $w * $size, 1 + ($w + 1) * $size);
        }

        return $pools;
    }
}
