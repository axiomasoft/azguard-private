<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class_exists(W::class);

class PaginationMorphClient extends VisibilityClient
{
    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'project_id');
    }
}

beforeEach(function (): void {
    W::seed();
    Schema::table('visibility_clients', fn (Blueprint $table) => $table->string('target_type')->nullable());
    DB::table('visibility_clients')->update(['target_type' => VisibilityProject::class]);
});
afterEach(fn () => W::reset());

it('paginates distinct relation rows with exact total order and eager morph contexts', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1), W::grant(3)]));
    $query = PaginationMorphClient::query()->with(['project', 'target'])->whereHasMorph('target', [VisibilityProject::class])
        ->select('visibility_clients.*')->distinct()->orderByDesc('visibility_clients.id');
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    $page = (clone $query)->paginate(1, ['*'], 'page', 1);
    $next = (clone $query)->paginate(1, ['*'], 'page', 2);
    expect($page->total())->toBe(2)->and((clone $query)->count())->toBe(2)
        ->and($page->pluck('id')->all())->toBe([103])->and($next->pluck('id')->all())->toBe([101])
        ->and($page->first()->relationLoaded('project'))->toBeTrue()->and($page->first()->project->id)->toBe(3)
        ->and($page->first()->relationLoaded('target'))->toBeTrue()->and($page->first()->target->id)->toBe(3);
});

it('keeps a cursor built before a grant change and requires a new query for fresh visibility', function (): void {
    $source = new VisibilitySource(direct: [W::grant(1), W::grant(3)]);
    [$visibility, $panel] = W::compile($source);
    $query = $visibility->visibleTo($panel, VisibilityClient::query()->orderBy('id'), W::subject(), 'orders.view');
    $cursor = $query->cursor();
    $iterator = $cursor->getIterator();
    $iterator->rewind();
    expect($iterator->current()->id)->toBe(101);
    $source->direct = [W::grant(1)];
    $iterator->next();
    expect($iterator->current()->id)->toBe(103)
        ->and($visibility->visibleTo($panel, VisibilityClient::query()->orderBy('id'), W::subject(), 'orders.view')->pluck('id')->all())->toBe([101]);
});
