<?php

declare(strict_types=1);

namespace AzGuardBench\Load\Profiles;

use AzGuard\Facades\AzGuard;
use AzGuardBench\Load\Catalog;
use AzGuardBench\Load\Dataset;
use AzGuardBench\Load\Stage;
use AzGuardBench\Load\Stand;
use AzGuardBench\Load\Tier;
use Filament\Facades\Filament;
use RuntimeException;

/** Filament resource count and page with a policy comprising sixteen predicates. */
final class FilamentPagination extends BaseProfile
{
    public function id(): string
    {
        return 'filament-pagination';
    }

    public function description(): string
    {
        return 'Filament resource count and page with a policy-heavy predicate';
    }

    public function stages(Tier $tier): array
    {
        return $this->ladder($tier, 'filament', max(10, intdiv($tier->iterations, 4)));
    }

    public function prepare(Stand $stand, Tier $tier, Stage $stage): void
    {
        $user = Dataset::model(Catalog::class('FilamentUser'), 10);
        AzGuard::actingAs('benchmark', static fn () => AzGuard::panel('filament-bench')->for($user)->grantPermission('posts.view'));
        $this->serve();
    }

    public function boot(Stand $stand, Tier $tier, Stage $stage, int $worker): void
    {
        $this->serve();
    }

    public function iteration(Stand $stand, Tier $tier, Stage $stage, int $worker, int $i): string
    {
        $stand->newRequest();
        $resource = Catalog::class('PostResource');
        $query = $resource::getEloquentQuery()->orderBy('id');

        if ($i % 2 === 0) {
            $page = $query->paginate(25, ['*'], 'page', 1);

            if ($page->total() !== $this->expected($tier) || count($page->items()) !== min(25, $this->expected($tier))) {
                throw new RuntimeException('Filament page or count was incorrect.');
            }
            foreach ($page->items() as $post) {
                if ((int) $post->user_id !== 10) {
                    throw new RuntimeException('Filament returned another user’s post.');
                }
            }

            return 'page';
        }

        if ($query->count() !== $this->expected($tier)) {
            throw new RuntimeException('Filament count was incorrect.');
        }

        return 'count';
    }

    public function verify(Stand $stand, Tier $tier, Stage $stage): array
    {
        $this->serve();
        $resource = Catalog::class('PostResource');
        $count = $resource::getEloquentQuery()->count();

        return [['name' => 'Filament count matches visible posts', 'ok' => $count === $this->expected($tier), 'detail' => "{$count} expected ".$this->expected($tier)]];
    }

    private function expected(Tier $tier): int
    {
        return intdiv($tier->posts, $tier->users) + ($tier->posts % $tier->users >= 10 ? 1 : 0);
    }

    private function serve(): void
    {
        auth()->guard('web')->setUser(Dataset::model(Catalog::class('FilamentUser'), 10));
        Filament::setCurrentPanel('bench-ui');
        Filament::bootCurrentPanel();
    }
}
