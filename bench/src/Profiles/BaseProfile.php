<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Profile;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use RuntimeException;

/** Defaults of a profile and the subjects of the dataset. */
abstract class BaseProfile implements Profile
{
    public function prepare(Stand $stand, Tier $tier, Stage $stage): void {}

    public function boot(Stand $stand, Tier $tier, Stage $stage, int $worker): void {}

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        return [];
    }

    /** The subject wrapper of user $id in the `bench` panel; the model is held, as an authenticated user is. */
    protected function subject(int $id, string $panel = 'bench'): SubjectAccess
    {
        return AzGuard::panel($panel)->for(Dataset::model(Catalog::class('User'), $id));
    }

    /**
     * The decision of a check, as `hasPermission()` takes it, except that an engine error (`*_error` reasons, which
     * a check answers with a fail-closed false) is thrown: the bench must not time the error path as a denial.
     */
    protected function check(SubjectAccess $subject, string $permission): bool
    {
        $decision = $subject->decide($permission);

        if (str_ends_with($decision->reason->value, '_error')) {
            throw new RuntimeException("Check of {$permission} failed: {$decision->reason->value} ".($decision->message ?? ''));
        }

        return $decision->allowed();
    }

    /** A light user (not the heavy one), chosen by the worker's seeded generator. */
    protected function randomUser(Tier $tier): int
    {
        return mt_rand(2, $tier->users);
    }

    /** A permission of the catalog, chosen by the worker's seeded generator. */
    protected function randomPermission(): string
    {
        return 'g'.mt_rand(1, Catalog::GROUPS).'.p'.mt_rand(1, Catalog::CASES);
    }

    /** @return list<Stage> one stage per worker count of the tier's ladder */
    protected function ladder(Tier $tier, string $prefix, ?int $iterations = null): array
    {
        return array_map(static fn (int $workers): Stage => new Stage($prefix.':w'.$workers, $workers, $iterations ?? $tier->iterations, $tier->warmup), $tier->ladder);
    }
}
