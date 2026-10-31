<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\ContributionQualifier;
use AzGuard\Authorization\Visibility;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Roles\SelectsAutomaticRoleScopes;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Sources\Internal\EvaluatesAutomaticRoles;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Visibility\VisibilityPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

#[Role('queryable-automatic')]
#[NotGrantable]
class ParityAutomaticRole extends BaseRole implements GrantedAutomatically, SelectsAutomaticRoleScopes
{
    public static bool $applies = false;

    public static ?string $observedType = 'unset';

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return self::$applies && $subject->getKey() === 1 && $scope->context->isGlobal();
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        self::$observedType = $contextType;

        return self::$applies && $subject->getKey() === 1 ? [AccessScope::in($context->scope()->tenant)] : [];
    }
}

#[Role('stored-only')]
#[NotGrantable]
class ParityNonGrantableRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }
}

class ParityRoleSource extends VisibilitySource implements ProvidesRoles
{
    public function roles(Panel $panel): iterable
    {
        return [new ParityAutomaticRole, new ParityNonGrantableRole];
    }
}

class ParityAutomaticSource extends ParityRoleSource implements EvaluatesAutomaticRoles {}

#[Role('mapped-automatic')]
#[NotGrantable]
class MappedParityRole extends BaseRole implements GrantedAutomatically, SelectsAutomaticRoleScopes
{
    public static string $behavior = 'normal';

    public static ?string $observedType = null;

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [new VisibilityProjectScope];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        if (self::$behavior === 'global-context') {
            return $scope->context->isGlobal();
        }

        return $subject->getKey() === 1 && ! $scope->context->isGlobal() && in_array($scope->context->id(), ['1', '3'], true)
            && (self::$behavior === 'global-tenant' ? $scope->tenant->isGlobal() : ! W::$tenanted || $scope->tenant->equals(W::tenant('A')));
    }

    public function exactAutomaticScopes(Model $subject, ?string $contextType, EvaluationContext $context): ?array
    {
        self::$observedType = $contextType;

        if (self::$behavior === 'null') {
            return null;
        }

        if (self::$behavior === 'throws') {
            throw new RuntimeException('private adapter secret');
        }

        if (self::$behavior === 'partial') {
            $partial = [W::scope(1)];

            throw new RuntimeException('Selection incomplete after '.count($partial).' scope.');
        }

        if (self::$behavior === 'bad-list') {
            return [3 => W::scope(1)];
        }

        if (self::$behavior === 'wrong-type') {
            return [AccessScope::in($context->scope()->tenant, AssignmentScopeRef::of('other', 1))];
        }

        if (self::$behavior === 'forged') {
            return [W::role(key: 'mapped-automatic')];
        }

        if (self::$behavior === 'tenant') {
            return [AccessScope::in(TenantRef::of('org', 'B'), AssignmentScopeRef::of('project', 1))];
        }

        if (self::$behavior === 'overflow') {
            return array_fill(0, 1001, W::scope(1));
        }

        if (self::$behavior === 'global-context') {
            return [AccessScope::in($context->scope()->tenant)];
        }
        $tenant = self::$behavior === 'global-tenant' ? TenantRef::global() : $context->scope()->tenant;

        return [AccessScope::in($tenant, AssignmentScopeRef::of($contextType ?? 'project', 1)), AccessScope::in($tenant, AssignmentScopeRef::of($contextType ?? 'project', 3))];
    }
}

class ParityOnlyAssignments extends VisibilitySource
{
    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        return [];
    }
}

#[RequiresGrant]
enum ParityEveryonePermission: string
{
    #[GrantedToAll] case Read = 'everyone.read';
}

class ParityGlobalProject extends VisibilityProject
{
    public function azguardContextType(): string
    {
        return 'global';
    }
}

class ParityGlobalScope extends VisibilityProjectScope
{
    public function type(): string
    {
        return 'global';
    }

    public function query(): Builder
    {
        return ParityGlobalProject::query();
    }
}

#[Role('global-alias-automatic')]
#[NotGrantable]
class ParityGlobalAliasRole extends MappedParityRole
{
    public function scopes(): array
    {
        return [new ParityGlobalScope];
    }
}

class ParityActiveCondition implements FiltersAccessQueries, GrantCondition
{
    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
    {
        return (bool) $context->resource()?->getAttribute('active');
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return P::branch($contribution, P::eq('active', true));
    }
}

function parityGlobalWorld(ParityRoleSource $source): array
{
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([$source])->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(Visibility::class);

    return [app(Visibility::class), $registry->get('admin'), app(Authorizer::class)];
}

beforeEach(function (): void {
    W::seed();
    ParityAutomaticRole::$applies = false;
    ParityAutomaticRole::$observedType = 'unset';
    MappedParityRole::$behavior = 'normal';
    MappedParityRole::$observedType = null;
});
afterEach(fn () => W::reset());

it('qualifies a trusted automatic witness in scalar and exact global visibility', function (): void {
    $item = W::role(key: 'queryable-automatic');
    $source = new ParityAutomaticSource(roles: [$item]);
    [$visibility, $panel, $authorizer] = parityGlobalWorld($source);
    $record = User::query()->firstOrFail();
    expect($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed())->toBeTrue()
        ->and(ContributionQualifier::qualify($source, $item, app(PanelRegistry::class)->catalog('admin'), $panel, W::scope()->tenant, now()->toDateTimeImmutable())[1])->toBeNull();
    expect($visibility->visibleTo($panel, User::query(), W::subject(), 'orders.view')->pluck('id')->all())->toBe([1]);
    expect(ParityAutomaticRole::$observedType)->toBeNull();
});

it('selects folder automatic roles exactly in global visibility', function (bool $applies, int $subjectId): void {
    ParityAutomaticRole::$applies = $applies;
    [$visibility, $panel, $authorizer] = parityGlobalWorld(new ParityRoleSource);
    $record = User::query()->firstOrFail();
    $subject = SubjectRef::of('user', $subjectId);
    $expected = $applies && $subjectId === 1;
    expect($authorizer->decide($panel, AccessRequest::for($subject, PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed())->toBe($expected);
    expect($visibility->visibleTo($panel, User::query(), $subject, 'orders.view')->pluck('id')->all())->toBe($expected ? [1] : []);
})->with([true, false])->with([1, 999]);

it('rejects unknown, nongrantable and forged automatic contributions', function (string $case): void {
    $role = match ($case) {
        'unknown' => 'missing', 'stored' => 'stored-only', default => 'queryable-automatic',
    };
    $item = $case === 'grant' ? Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', W::scope(), RoleKey::of('admin', $role)) : W::role(key: $role);
    $class = $case === 'untrusted' ? ParityRoleSource::class : ParityAutomaticSource::class;
    $source = $item instanceof Grant ? new $class(direct: [$item]) : new $class(roles: [$item]);
    [$visibility, $panel, $authorizer] = parityGlobalWorld($source);
    $record = User::query()->firstOrFail();
    expect($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed())->toBeFalse()
        ->and(ContributionQualifier::qualify($source, $item, app(PanelRegistry::class)->catalog('admin'), $panel, W::scope()->tenant, now()->toDateTimeImmutable())[1])->toBe($case === 'unknown' ? 'unknown_role' : 'not_grantable_role');
    expect($visibility->visibleTo($panel, User::query(), W::subject(), 'orders.view')->count())->toBe(0);
})->with(['unknown', 'stored', 'untrusted', 'grant']);

it('matches mapped scalar and exact results in each assignment mode with row-dependent conditions', function (string $mode): void {
    [$visibility, $panel, $authorizer] = W::compile(configure: fn (PanelBuilder $p) => $p->roles([MappedParityRole::class])->grantConditions([ParityActiveCondition::class]), mode: $mode);
    $ids = [];
    foreach (VisibilityProject::query()->orderBy('id')->get() as $record) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed()) {
            $ids[] = $record->id;
        }
    }
    expect($ids)->toBe([1, 3])->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(MappedParityRole::$observedType)->toBe('project');
})->with(['inherit', 'isolated', 'required']);

it('honours tenant boundaries and the global role allowlist', function (bool $allowed): void {
    MappedParityRole::$behavior = 'global-tenant';
    [$visibility, $panel, $authorizer] = W::compile(configure: fn (PanelBuilder $p) => $p->roles([MappedParityRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)->allowGlobalRoles($allowed ? [MappedParityRole::class] : [])), tenant: true);
    $ids = [];
    foreach (VisibilityProject::query()->where('org', 'A')->orderBy('id')->get() as $record) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed()) {
            $ids[] = $record->id;
        }
    }
    expect($ids)->toBe($allowed ? [1, 3] : [])->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all())->toBe($ids);
})->with([true, false]);

it('fails closed for invalid, unavailable, throwing or excessive automatic selections', function (string $behavior): void {
    MappedParityRole::$behavior = $behavior;
    [$visibility, $panel] = W::compile(configure: fn (PanelBuilder $p) => $p->roles([MappedParityRole::class]), tenant: $behavior === 'tenant');
    $query = VisibilityProject::query();
    $sql = $query->toSql();

    try {
        $visibility->visibleTo($panel, $query, W::subject(), 'orders.view', $behavior === 'tenant' ? W::scope() : null);
        $this->fail('Unsafe automatic selection must fail.');
    } catch (VisibilityNotSupportedException $e) {
        expect($e->getMessage())->not->toContain('private adapter secret')->and($e->getPrevious())->toBeNull()->and($query->toSql())->toBe($sql);
    }
})->with(['null', 'throws', 'partial', 'bad-list', 'wrong-type', 'forged', 'tenant', 'overflow']);

it('retains independent every-context grants when automatic witnesses are present', function (): void {
    MappedParityRole::$behavior = 'global-context';
    [$visibility, $panel, $authorizer] = W::compile(configure: fn (PanelBuilder $p) => $p->roles([MappedParityRole::class]), mode: 'isolated', extra: [ParityEveryonePermission::class]);
    $ids = [];
    foreach (VisibilityProject::query()->orderBy('id')->get() as $record) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'everyone.read'))->on(null, $record))->allowed()) {
            $ids[] = $record->id;
        }
    }
    expect($ids)->toBe([1, 2, 3, 4])->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), ParityEveryonePermission::Read)->orderBy('id')->pluck('id')->all())->toBe($ids);
    foreach (VisibilityProject::query()->get() as $record) {
        expect($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed())->toBeFalse();
    }
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->count())->toBe(0);
});

it('rejects an unsupported sibling even when an automatic source grants', function (): void {
    $bad = new ParityOnlyAssignments(name: 'bad');
    $bad->selection = static fn () => null;
    [$visibility, $panel] = W::compile(new VisibilitySource(roles: [W::role(key: 'root')]), configure: fn (PanelBuilder $p) => $p->roles([MappedParityRole::class]), extra: [$bad]);
    $query = VisibilityProject::query();
    $sql = $query->toSql();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class, 'unsupported_selection')
        ->and($query->toSql())->toBe($sql);
});

it('distinguishes a concrete global scope type from no mapping', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $p) => $p->for(User::class)->permissions([new VisibilitySource])->roles([ParityGlobalAliasRole::class])
        ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new ParityGlobalScope], evaluator: ScopeEvaluator::class))]);
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(Visibility::class);
    $panel = $registry->get('admin');
    $ids = [];
    foreach (ParityGlobalProject::query()->orderBy('id')->get() as $record) {
        if (app(Authorizer::class)->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $record))->allowed()) {
            $ids[] = $record->id;
        }
    }
    expect($ids)->toBe([1, 3])->and(app(Visibility::class)->visibleTo($panel, ParityGlobalProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(MappedParityRole::$observedType)->toBe('global');
});
