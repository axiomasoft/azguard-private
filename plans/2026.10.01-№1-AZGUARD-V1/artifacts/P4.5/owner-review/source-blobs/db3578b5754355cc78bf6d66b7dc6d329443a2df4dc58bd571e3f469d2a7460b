<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Sources\Relation\EditorRole;
use AzGuard\Tests\Fixtures\Sources\Relation\Project;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\QuerylessDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;

beforeEach(fn () => RelationWorld::seed());
afterEach(fn () => RelationWorld::reset());

it('describes request volatile role and selection capabilities', function (): void {
    $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    [$panel] = RelationWorld::compile([$source]);
    $description = $source->describe($panel);
    expect($source->id())->toBe('relation:project')->and($source->volatility())->toBe(Volatility::Request)
        ->and($description->id)->toBe($source->id())->and($description->class)->toBe(RelationSource::class)
        ->and($description->dynamic)->toBeFalse();
    foreach ([ProvidesRoleGrants::class, FiltersQueries::class, DescribesSchema::class] as $capability) {
        expect($source)->toBeInstanceOf($capability)->and($description->capabilities)->toContain($capability);
    }
});

it('resolves a model shortcut through its role bound tenant aware definition', function (): void {
    RelationWorld::attach(RelationWorld::project());
    $source = RelationSource::make(Project::class, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source]);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));
    expect($source->id())->toBe('relation:project')->and($roles)->toHaveCount(1)
        ->and($roles[0]->scope->tenant->type())->toBe('organization')->and($roles[0]->scope->tenant->id())->toBe('A');
});

it('rejects missing or ambiguous model shortcut definitions during build', function (bool $ambiguous): void {
    EditorRole::$definitions = $ambiguous ? [new ProjectDefinition('project'), new ProjectDefinition('other_project')] : [];
    expect(fn () => RelationWorld::compile([RelationSource::make(Project::class, 'members', 'editor')]))->toThrow(DefinitionException::class);
})->with([false, true]);

it('rejects unknown static roles malformed relations and non-queryable definitions before runtime', function (string $invalid): void {
    expect(function () use ($invalid): void {
        $definition = match ($invalid) {
            'queryless' => new QuerylessDefinition,
            'model null' => new ProjectDefinition(recordClass: null),
            default => new ProjectDefinition,
        };
        $via = match ($invalid) {
            'missing relation' => 'missingRelation',
            'malformed relation' => 'malformed',
            default => 'members',
        };
        RelationWorld::compile([RelationSource::make($definition, $via, $invalid === 'unknown role' ? 'ghost' : 'editor')]);
    })->toThrow(DefinitionException::class);
})->with(['unknown role', 'missing relation', 'malformed relation', 'queryless', 'model null']);

it('rejects duplicate source ids even when different relations supply the same scope type', function (): void {
    expect(fn () => RelationWorld::compile([
        RelationSource::make(new ProjectDefinition, 'members', 'pivot.role'),
        RelationSource::make(new ProjectDefinition, 'lead', 'owner'),
    ]))->toThrow(DefinitionException::class);
});

it('rejects a computed source id longer than the source label limit at build', function (): void {
    $alias = str_repeat('a', 120);
    expect(fn () => RelationWorld::compile([RelationSource::make(new ProjectDefinition($alias), 'members', 'editor')]))
        ->toThrow(DefinitionException::class);
});

it('validates a static role against a restored catalog on the cached build path', function (): void {
    $source = RelationSource::make(new ProjectDefinition, 'members', 'editor');
    [,, $live] = RelationWorld::compile([$source]);
    $entries = $live->snapshot();
    unset($entries['admin']['catalog']['roles']['editor']);
    $cache = new CatalogCache(sys_get_temp_dir().'/azguard-relation-'.bin2hex(random_bytes(6)).'.php');

    try {
        $cache->write($live->buildId(), $entries);
        $cached = new PanelRegistry(app(), cache: static fn (): CatalogCache => $cache);
        $cached->register(AdminPanel::class);
        expect(fn () => $cached->freeze())->toThrow(DefinitionException::class, 'editor');
    } finally {
        @unlink($cache->path());
    }
});

it('uses an explicit descriptor alias and global tenant for a non-tenant model shortcut', function (): void {
    RelationWorld::attach(RelationWorld::project());
    EditorRole::$definitions = [new ProjectDefinition('work_project', global: true)];
    $scope = AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('work_project', 7));
    $source = RelationSource::make(Project::class, 'members', 'pivot.role');
    [, $frame] = RelationWorld::compile([$source], $scope);
    $roles = RelationWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$scope], $frame));
    expect($source->id())->toBe('relation:work_project')->and($roles)->toHaveCount(1)
        ->and($roles[0]->scope->equals($scope))->toBeTrue();
});
