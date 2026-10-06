<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[RequiresGrant]
enum VisibilityEveryonePermission: string
{
    #[GrantedToAll] case Read = 'everyone.read';
}

class VisibilityExternalAdapter implements AssignmentScopeAccessAdapter
{
    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
    {
        return $ref->id() !== '2';
    }

    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
    {
        return array_combine(array_map(static fn ($ref) => $ref->key(), $refs), array_map(fn ($ref) => $this->allows($ref, $runtime), $refs));
    }

    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
    {
        return $contextQuery->whereKeyNot(2);
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('composes actual RelationSource witnesses with direct grants without losing separate contexts', function (): void {
    Schema::create('visibility_members', static function (Blueprint $table): void {
        $table->integer('project_id');
        $table->integer('user_id');
    });
    DB::table('visibility_members')->insert([
        ['project_id' => 1, 'user_id' => 1], ['project_id' => 2, 'user_id' => 1], ['project_id' => 4, 'user_id' => 2],
    ]);
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(3)]), extra: [RelationSource::make(new VisibilityProjectScope, 'members', 'viewer')]);
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3]);
});

it('uses fixed GrantedToAll folder witnesses in isolated and required contexts', function (string $mode): void {
    [$visibility, $panel, $authorizer] = W::compile(mode: $mode, extra: [VisibilityEveryonePermission::class]);
    $visible = $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), VisibilityEveryonePermission::Read)->orderBy('id')->pluck('id')->all();
    expect($visible)->toBe([1, 2, 3, 4]);
    foreach (VisibilityProject::query()->get() as $record) {
        expect($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'everyone.read'))->on(null, $record))->allowed())->toBeTrue();
    }
})->with(['isolated', 'required']);

it('constrains a queryable external scope adapter without letting it replace ownership', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1), W::grant(2)]), fn (PanelBuilder $panel) => $panel->scopes(
        AssignmentScopePolicy::inherit(new VisibilityProjectScope)->accessAdapter('project', new VisibilityExternalAdapter),
    ));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
});

it('blocks native scope filter structure changes and caught terminal query execution', function (bool $terminal): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), fn (PanelBuilder $panel) => $panel->scopes(AssignmentScopePolicy::inherit(
        (new VisibilityProjectScope)->filter(static function (Builder $query) use ($terminal): void {
            if ($terminal) {
                try {
                    $query->count();
                } catch (RuntimeException) {
                }
            } else {
                $query->orderBy('id');
            }
        }),
    )));
    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class);
})->with([false, true]);
