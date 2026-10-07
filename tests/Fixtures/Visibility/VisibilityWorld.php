<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Visibility;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\BaseAssignmentScope;
use AzGuard\Scopes\ContextAware;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class VisibilityClient extends Model implements ProvidesAccessScope, ProvidesAssignmentScope
{
    use ContextAware;

    protected $table = 'visibility_clients';

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'project';
    }

    public function azguardContextRelation(): ?string
    {
        return 'project';
    }

    /** @return BelongsTo<VisibilityProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(VisibilityProject::class, 'project_id');
    }

    public function azguardScope(): AccessScope
    {
        return $this->azguardContext()?->azguardScope() ?? AccessScope::in(TenantRef::global());
    }
}

class VisibilityProjectScope extends BaseAssignmentScope implements FiltersAccessQueries
{
    public function type(): string
    {
        return 'project';
    }

    public function query(): Builder
    {
        return VisibilityWorld::$projectQuery === null ? VisibilityProject::query() : (VisibilityWorld::$projectQuery)();
    }

    public function tenantOf(Model $record): TenantRef
    {
        return VisibilityWorld::tenant((string) $record->getAttribute('org'));
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return VisibilityWorld::$tenanted ? P::eq('org', $context->scope()->tenant->id()) : P::pass();
    }
}

#[Role('viewer')]
class VisibilityRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [(new VisibilityProjectScope)->filter(static function (Builder $query, $runtime): void {
            $city = $runtime->grant?->fields()['city'] ?? null;

            if ($city !== null) {
                $query->where('city', $city);
            }
        })];
    }
}

class VisibilitySource extends GeneratedSource implements FiltersQueries
{
    public int $selectionReads = 0;

    public ?Closure $selection = null;

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        foreach (parent::grants($subject, $scopes, $context) as $item) {
            if (! $item instanceof Grant || in_array($item->scope, $scopes)) {
                yield $item;
            }
        }
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        foreach (parent::roleGrants($subject, $scopes, $context) as $item) {
            if (! $item instanceof RoleContribution || in_array($item->scope, $scopes)) {
                yield $item;
            }
        }
    }

    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?AssignmentScopeSelection
    {
        $this->selectionReads++;

        if ($this->selection !== null) {
            return ($this->selection)($subject, $key, $contextType, $context);
        }
        $items = [...$this->direct, ...$this->roles];
        $refs = [];
        foreach ($items as $item) {
            if ($item->scope->context->isGlobal()) {
                return AssignmentScopeSelection::everywhere($items);
            }
            $refs[$item->scope->context->key()] = $item->scope->context;
        }

        return AssignmentScopeSelection::in(array_values($refs), $items);
    }
}

class VisibilityPolicy implements FiltersAccessQueries
{
    #[Decides('orders.policy')]
    public function view(?Model $user, ?Model $resource): ?bool
    {
        return match ($resource?->getAttribute('state')) {
            'allow' => true, 'deny' => false, default => null,
        };
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return P::partition(P::eq('state', 'allow'), P::eq('state', 'deny'), P::not(P::in('state', ['allow', 'deny'])));
    }
}

class VisibilityBefore implements FiltersAccessQueries
{
    public function __invoke(AccessRequest $request, EvaluationContext $context): BeforeResult
    {
        return $context->resource()?->getAttribute('active') ? BeforeResult::Continue : BeforeResult::Deny;
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        $pass = P::eq('active', true);

        return P::beforePartition(P::not($pass), $pass);
    }
}

class VisibilityCondition implements FiltersAccessQueries, GrantCondition
{
    public static array $times = [];

    public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
    {
        return ! isset($grant->fields()['city']) || $context->resource()?->getAttribute('city') === $grant->fields()['city'];
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        self::$times[] = $context->now();

        return P::branch($contribution, isset($contribution->fields()['city']) ? P::eq('city', $contribution->fields()['city']) : P::pass());
    }
}

class VisibilityRestriction implements FiltersAccessQueries, Restriction
{
    public static bool $exempt = false;

    public static bool $applies = true;

    public function key(): string
    {
        return 'city';
    }

    public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
    {
        return self::$applies;
    }

    public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
    {
        return $context->resource()?->getAttribute('city') === 'Paris' ? RestrictionResult::pass() : RestrictionResult::deny();
    }

    public function exemptsSuperAdmin(): bool
    {
        return self::$exempt;
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        return P::eq('city', 'Paris');
    }
}

final class VisibilityWorld
{
    public static bool $tenanted = false;

    /** @var (Closure(): Builder<VisibilityProject>)|null */
    public static ?Closure $projectQuery = null;

    public static function seed(): void
    {
        self::$tenanted = false;
        self::$projectQuery = null;
        VisibilityCondition::$times = [];
        VisibilityRestriction::$exempt = false;
        VisibilityRestriction::$applies = true;
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        Relation::morphMap(['user' => User::class, 'org' => Organization::class], false);

        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Visibility tests require isolated SQLite :memory:.');
        }
        Schema::create('users', fn (Blueprint $table) => $table->id());
        User::query()->insert(['id' => 1]);
        foreach (['visibility_projects', 'visibility_clients'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->integer('project_id')->nullable();
                $table->string('org');
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->boolean('active')->nullable();
            });
        }
        $rows = [
            ['id' => 1, 'project_id' => null, 'org' => 'A', 'city' => 'Paris', 'state' => 'allow', 'active' => true],
            ['id' => 2, 'project_id' => null, 'org' => 'A', 'city' => 'Rome', 'state' => 'deny', 'active' => false],
            ['id' => 3, 'project_id' => null, 'org' => 'A', 'city' => null, 'state' => null, 'active' => true],
            ['id' => 4, 'project_id' => null, 'org' => 'B', 'city' => 'Paris', 'state' => 'allow', 'active' => true],
        ];
        VisibilityProject::query()->insert($rows);
        VisibilityClient::query()->insert(array_map(static fn (array $row): array => ['id' => $row['id'] + 100, 'project_id' => $row['id'], ...array_diff_key($row, ['id' => true, 'project_id' => true])], $rows));
    }

    public static function reset(): void
    {
        Carbon::setTestNow();
        Relation::morphMap([], false);
        self::$tenanted = false;
        self::$projectQuery = null;
    }

    /** @return array{Visibility, Panel, Authorizer} */
    public static function compile(?VisibilitySource $source = null, ?Closure $configure = null, string $mode = 'inherit', bool $tenant = false, array $extra = []): array
    {
        self::$tenanted = $tenant;
        $source ??= new VisibilitySource;
        [, , $registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use ($source, $configure, $mode, $tenant, $extra): void {
            $panel->for(User::class)->permissions([$source, ...$extra])->roles([VisibilityRole::class, GrantableRootRole::class])
                ->policies([PolicyBinding::for('orders.policy', VisibilityPolicy::class)])
                ->scopes(AssignmentScopePolicy::{$mode}(new VisibilityProjectScope));

            if ($tenant) {
                $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership));
            }
            $configure?->__invoke($panel);
        }]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);
        app()->forgetInstance(Visibility::class);

        return [app(Visibility::class), $registry->get('admin'), app(Authorizer::class)];
    }

    public static function tenant(string $id = 'A'): TenantRef
    {
        return self::$tenanted ? TenantRef::of('org', $id) : TenantRef::global();
    }

    public static function scope(?int $id = null, string $tenant = 'A'): AccessScope
    {
        return AccessScope::in(self::tenant($tenant), $id === null ? null : AssignmentScopeRef::of('project', $id));
    }

    public static function subject(): SubjectRef
    {
        return SubjectRef::of('user', 1);
    }

    public static function grant(?int $id = null, array $fields = [], ?DateTimeImmutable $expiry = null, string $source = 'generated', string $tenant = 'A'): Grant
    {
        return Grant::of(PermissionPattern::of('admin', 'orders.view'), $source, self::scope($id, $tenant), expiresAt: $expiry, fields: $fields);
    }

    public static function role(?int $id = null, array $fields = [], string $key = 'viewer', ?DateTimeImmutable $expiry = null): RoleContribution
    {
        return RoleContribution::of(RoleKey::of('admin', $key), self::scope($id), 'generated', expiresAt: $expiry, fields: $fields);
    }
}
