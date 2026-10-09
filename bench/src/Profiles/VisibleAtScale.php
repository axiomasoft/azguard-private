<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * List filtering at scale: `visibleTo()` compiles the policy predicate to SQL over the whole posts table, so the cost
 * should follow the query plan, not the number of rows. Operations alternate the first page of 25 and the count, each
 * in a new request, for users who hold `posts.view`.
 */
final class VisibleAtScale extends BaseProfile
{
    public function id(): string
    {
        return 'visible-at-scale';
    }

    public function description(): string
    {
        return 'visibleTo() first page and count over the posts table';
    }

    public function stages(Tier $tier): array
    {
        return $this->ladder($tier, 'visible', max(20, intdiv($tier->iterations, 4)));
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $stand->newRequest();
        $query = $this->visible($this->viewer($tier));

        if ($i % 2 === 0) {
            $query->limit(25)->get();

            return 'page';
        }
        $query->count();

        return 'count';
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $stand->newRequest();
        $expected = intdiv($tier->posts, $tier->users) + ($tier->posts % $tier->users >= 10 ? 1 : 0);
        $count = $this->visible(10)->count();

        return [['name' => 'user 10 sees exactly its own posts', 'ok' => $count === $expected, 'detail' => "{$count} of {$expected}"]];
    }

    /** A user who holds posts.view: every tenth one. */
    private function viewer(Tier $tier): int
    {
        return 10 * mt_rand(1, intdiv($tier->users, 10));
    }

    /** @return Builder<Model> */
    private function visible(int $user): Builder
    {
        $panel = AzGuard::panel('bench');

        return $panel->visibility()->visibleTo($panel->definition(), Dataset::query('Post'), Dataset::model(Catalog::class('User'), $user), 'posts.view');
    }
}
