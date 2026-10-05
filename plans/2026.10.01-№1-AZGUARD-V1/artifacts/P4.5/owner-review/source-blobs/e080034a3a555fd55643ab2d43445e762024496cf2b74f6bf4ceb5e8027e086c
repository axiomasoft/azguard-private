<?php

declare(strict_types=1);

use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Sources\Relation\Member;
use AzGuard\Tests\Fixtures\Sources\Relation\Project;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationPermission;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use AzGuard\Tests\Fixtures\Sources\Relation\Store;
use AzGuard\Tests\Fixtures\Sources\Relation\Team;
use Illuminate\Database\Eloquent\Model;

beforeEach(fn () => RelationWorld::seed());
afterEach(function (): void {
    RelationWorld::reset();
    Model::preventLazyLoading(false);
});

it('reads membership from the related root and uses the exact subject id and model identity', function (): void {
    $project = RelationWorld::project();
    RelationWorld::attach($project, 2, 'owner');
    RelationWorld::attach($project, 1, 'editor');
    RelationWorld::attach(RelationWorld::project(8), 2, 'owner');
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [RelationWorld::scope(7), RelationWorld::scope(8)], $frame));

    expect($roles)->toHaveCount(1)->and($roles[0]->role->full())->toBe('admin:editor')
        ->and($roles[0]->scope->equals(RelationWorld::scope(7)))->toBeTrue()
        ->and($roles[0]->source)->toBe('relation:project')->and($roles[0]->origin)->toBe('relation')
        ->and(RelationWorld::items($source->roleGrants(SubjectRef::of('vendor', 1), [RelationWorld::scope(7)], $frame)))->toBe([])
        ->and(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 3), [RelationWorld::scope(7)], $frame)))->toBe([]);
});

it('supports pivot callbacks and null results without using another members pivot', function (): void {
    $project = RelationWorld::project();
    RelationWorld::attach($project, 2, 'owner', ['is_lead' => true]);
    RelationWorld::attach($project, 1, 'editor');
    $seen = [];
    $source = RelationSource::make(new ProjectDefinition, 'members', function (Model $pivot) use (&$seen): ?string {
        $seen[] = $pivot->getAttribute('member_id');

        return $pivot->getAttribute('role') === null ? null : ($pivot->getAttribute('is_lead') ? 'owner' : 'editor');
    });
    [, $frame] = RelationWorld::compile([$source]);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));
    expect($roles)->toHaveCount(1)->and($roles[0]->role->key())->toBe('editor')->and($seen)->toBe([1]);
    $project->members()->updateExistingPivot(1, ['role' => null]);
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame)))->toBe([]);
});

it('reads fresh membership and roles after changes with constrained eager loading', function (): void {
    $project = RelationWorld::project();
    RelationWorld::attach($project);
    RelationWorld::attach($project, 2, 'owner');
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    Model::preventLazyLoading();
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame))[0]->role->key())->toBe('editor');
    $project->members()->updateExistingPivot(1, ['role' => 'owner']);
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame))[0]->role->key())->toBe('owner');
    $project->members()->detach(1);
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame)))->toBe([]);
});

it('uses only exact requested tenant context pairs and never enumerates a global scalar context', function (): void {
    RelationWorld::attach(RelationWorld::project(7, 'A'));
    RelationWorld::attach(RelationWorld::project(8, 'B'));
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    $exact = [RelationWorld::scope(7, 'A'), RelationWorld::scope(8, 'B')];
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), $exact, $frame));
    expect($roles)->toHaveCount(2);
    foreach ($roles as $role) {
        expect($role->scope->equals($exact[0]) || $role->scope->equals($exact[1]))->toBeTrue();
    }
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [RelationWorld::scope(7, 'B'), RelationWorld::scope(8, 'A')], $frame)))->toBe([]);
    $connection = Project::query()->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [AccessScope::in(TenantRef::global()), RelationWorld::scope(7, type: 'store')], $frame)))->toBe([])
        ->and($connection->getQueryLog())->toBe([]);
});

it('takes a scalar scope tenant from resolve rather than assuming tenantOf is the resolver', function (): void {
    RelationWorld::attach(RelationWorld::project(7, 'A'));
    $definition = new ProjectDefinition(resolvedTenant: TenantRef::of('organization', 'B'));
    $source = RelationSource::make($definition, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source], RelationWorld::scope(7, 'B'));
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));
    expect($roles)->toHaveCount(1)->and($roles[0]->scope->equals($frame->scope()))->toBeTrue();
});

it('supports non-pivot static roles and related model callbacks', function (string $kind): void {
    $model = match ($kind) {
        'belongs to' => Store::class,
        'has many' => Team::class,
        'has one' => Project::class,
    };
    $via = match ($kind) {
        'belongs to' => 'owner',
        'has many' => 'members',
        'has one' => 'lead',
    };
    $record = $model::query()->create(['id' => 7, 'tenant_id' => 'A', 'owner_id' => 1]);
    Member::query()->whereKey(1)->update(['team_id' => 7, 'lead_project_id' => 7, 'role' => 'editor']);
    Member::query()->whereKey(2)->update(['team_id' => 7, 'role' => 'owner']);
    $definition = new ProjectDefinition(recordClass: $model);
    $source = RelationSource::make($definition, $via, 'owner');
    [, $frame] = RelationWorld::compile([$source]);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));
    expect($roles)->toHaveCount(1)->and($roles[0]->role->key())->toBe('owner');
    $callback = RelationSource::make($definition, $via, static fn (Model $related): ?string => $related->getAttribute('role'));
    [, $callbackFrame] = RelationWorld::compile([$callback]);
    $callbackRoles = RelationWorld::items($callback->roleGrants(SubjectRef::of('user', 1), [$callbackFrame->scope()], $callbackFrame));
    expect($callbackRoles)->toHaveCount(1)->and($callbackRoles[0]->role->key())->toBe('editor');
})->with(['belongs to', 'has many', 'has one']);

it('keeps polymorphic membership on the root morph type and exact member', function (): void {
    $project = RelationWorld::project();
    $project->morphMembers()->attach(1, ['role' => 'editor']);
    $project->morphMembers()->attach(2, ['role' => 'owner']);
    Project::query()->getConnection()->table('relation_memberships')->insert([
        'memberable_id' => 8, 'memberable_type' => 'store', 'member_id' => 1, 'role' => 'owner',
    ]);
    RelationWorld::project(8);
    $source = RelationSource::make(new ProjectDefinition, 'morphMembers', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope(), RelationWorld::scope(8)], $frame));
    expect($roles)->toHaveCount(1)->and($roles[0]->role->key())->toBe('editor')->and($roles[0]->scope->context->id())->toBe('7');
});

it('diagnoses an unknown raw pivot role in the authority stage without treating it as a grant', function (): void {
    RelationWorld::attach(RelationWorld::project(), role: 'ghost');
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [, $frame, $registry] = RelationWorld::compile([$source]);
    expect(RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame))[0]->role->key())->toBe('ghost');
    $trace = new Trace(true);
    [, $decision] = app(AuthorityStage::class)->decide(RelationWorld::request()->inScope($frame->scope()), $frame,
        $registry->catalog('admin'), $registry->catalog('admin')->get(RelationPermission::Edit->value), $trace);
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::NotGranted)
        ->and(array_column($trace->steps(), 'result'))->toContain('unknown_role');
});

it('fails closed in the authority stage for malformed roles callback errors and query failures', function (string $failure): void {
    RelationWorld::attach(RelationWorld::project(), role: $failure === 'malformed' ? 'editor ' : 'editor');
    $definition = new ProjectDefinition;
    $role = $failure === 'callback' ? static function (Model $pivot): ?string {
        throw new RuntimeException('Broken role callback');
    } : 'pivot.role';
    $source = RelationSource::make($definition, 'members', $role);
    [, $frame, $registry] = RelationWorld::compile([$source]);
    $definition->brokenQuery = $failure === 'query';
    $trace = new Trace(true);
    [, $decision] = app(AuthorityStage::class)->decide(RelationWorld::request()->inScope($frame->scope()), $frame,
        $registry->catalog('admin'), $registry->catalog('admin')->get(RelationPermission::Edit->value), $trace);
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError)
        ->and(array_column($trace->steps(), 'result'))->toContain('source_error');
})->with(['malformed', 'callback', 'query']);

it('rejects a contradictory resolved record instead of lending its tenant to another entity', function (): void {
    RelationWorld::attach(RelationWorld::project(7));
    $definition = new ProjectDefinition;
    $definition->resolvedRecord = RelationWorld::project(8);
    $source = RelationSource::make($definition, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    expect(fn () => RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [RelationWorld::scope(7)], $frame)))
        ->toThrow(InvalidSourceContributionException::class, 'different model or key');
});
