<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Scopes\MapsAssignmentScope;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Concerns\AuthorizesResource;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Scopes\ContextAware;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

final class ContractGateProvider extends ServiceProvider
{
    public function register(): void
    {
        Gate::define('contract-orders', static fn (): bool => true);
        Gate::define('contract-archived', static fn (): bool => false);
    }
}

#[Role('contract-filament-member')]
#[NotGrantable]
final class ContractFilamentMember extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return ['orders.view_any'];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getKey() === 1;
    }
}

final class ContractFilamentProject extends Model implements MapsAssignmentScope, ProvidesAccessScope, ProvidesAssignmentScope
{
    use ContextAware;

    protected $table = 'contract_filament_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'project';
    }

    public function azguardScope(): AccessScope
    {
        return AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', (string) $this->getKey()));
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'contract_filament_memberships', 'project_id', 'user_id')->withPivot('role');
    }
}

final class ContractFilamentProjectResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = ContractFilamentProject::class;

    protected static ?string $slug = 'contract-projects';
}

#[Role('contract-project-editor')]
final class ContractFilamentProjectEditor extends BaseRole
{
    public function permissions(): array
    {
        return ['contract-projects.view'];
    }

    public function scopes(): array
    {
        return [new ProjectDefinition(recordClass: ContractFilamentProject::class, global: true)];
    }
}

final class FilamentSourceContractTest extends TestCase
{
    use BootsFilament {
        getPackageProviders as filamentProviders;
    }

    protected function getPackageProviders($app): array
    {
        return [...$this->filamentProviders($app), ContractGateProvider::class];
    }

    protected function tearDown(): void
    {
        FilamentFixture::reset();
        parent::tearDown();
    }

    public function test_definitions_participate_in_scalar_batch_and_filament_checks(): void
    {
        FilamentFixture::$definitions = FilamentDefinitions::Resources;
        FilamentFixture::$abilities = ['view_any'];
        FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
        FilamentFixture::$guardPolicies = [];
        FilamentFixture::$pages = [];
        FilamentFixture::$widgets = [];
        $this->bootFilament();
        $user = User::query()->findOrFail(2);
        GateWorld::serve($user);

        $panel = app(PanelRegistry::class)->get('admin');
        $subject = SubjectRef::of('user', $user->getKey());
        $requests = [
            AccessRequest::for($subject, PermissionKey::of('admin', 'orders.view_any')),
            AccessRequest::for($subject, PermissionKey::of('admin', 'archived-orders.view_any')),
        ];
        $engine = app(Authorizer::class);
        $this->assertFalse($engine->decide($panel, $requests[0])->allowed(), 'A definition alone must not grant authority.');
        $source = array_values(array_filter($panel->attachedSources(), static fn ($source): bool => $source instanceof FilamentSource))[0];
        $this->assertNotInstanceOf(ProvidesGrants::class, $source);
        $this->assertNotInstanceOf(ProvidesRoleGrants::class, $source);
        $this->assertSame('unsupported', $source->describe($panel)->matrix['stable_cache']['status']);
        GateWorld::grant(['orders.view_any'], 2);
        $batch = $engine->decideMany($requests);
        $this->assertTrue($engine->decide($panel, $requests[0])->allowed());
        $this->assertFalse($engine->decide($panel, $requests[1])->allowed());
        $this->assertTrue($batch->get(0)->allowed());
        $this->assertFalse($batch->get(1)->allowed());
        $this->assertTrue(OrderResource::canViewAny());
        $this->assertFalse(ArchivedOrderResource::canViewAny());

        AzGuard::panel('admin')->for($user)->revokePermission('orders.view_any');
        $this->assertFalse(OrderResource::canViewAny());
        $this->assertSame('admin', Filament::getCurrentPanel()->getId());
    }

    public function test_gate_source_decides_filament_resource_definitions(): void
    {
        FilamentFixture::$definitions = FilamentDefinitions::Resources;
        FilamentFixture::$authority = 'policy';
        FilamentFixture::$abilities = ['view_any'];
        FilamentFixture::$guardPermissions = [FilamentSource::make('admin'), GateSource::make()
            ->map('orders.view_any', 'contract-orders')->map('archived-orders.view_any', 'contract-archived')];
        FilamentFixture::$pages = [];
        FilamentFixture::$widgets = [];
        $this->bootFilament();
        GateWorld::serve(User::query()->findOrFail(2));

        $this->assertTrue(OrderResource::canViewAny());
        $this->assertFalse(ArchivedOrderResource::canViewAny());
    }

    public function test_folder_automatic_role_grants_a_filament_resource_only_to_its_members(): void
    {
        FilamentFixture::$definitions = FilamentDefinitions::Resources;
        FilamentFixture::$abilities = ['view_any'];
        FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
        FilamentFixture::$adminRoles = [ContractFilamentMember::class];
        FilamentFixture::$pages = [];
        FilamentFixture::$widgets = [];
        $this->bootFilament();

        GateWorld::serve(User::query()->findOrFail(1));
        $this->assertTrue(OrderResource::canViewAny());
        $this->assertFalse(ArchivedOrderResource::canViewAny());
        GateWorld::serve(User::query()->findOrFail(2));
        $this->assertFalse(OrderResource::canViewAny());
    }

    public function test_relation_membership_controls_filament_record_and_visible_query(): void
    {
        $definition = new ProjectDefinition(recordClass: ContractFilamentProject::class, global: true);
        FilamentFixture::$definitions = FilamentDefinitions::Resources;
        FilamentFixture::$abilities = ['view'];
        FilamentFixture::$resources = [ContractFilamentProjectResource::class];
        FilamentFixture::$adminRoles = [ContractFilamentProjectEditor::class];
        FilamentFixture::$adminPlugins = [ScopesPlugin::inherit($definition)];
        FilamentFixture::$guardPermissions = [FilamentSource::make('admin'), RelationSource::make($definition, 'members', 'pivot.role')];
        FilamentFixture::$pages = FilamentFixture::$widgets = [];
        $this->bootFilament();
        Schema::create('contract_filament_projects', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('contract_filament_memberships', static function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
        });
        ContractFilamentProject::query()->insert([['id' => 7], ['id' => 8]]);
        $allowed = ContractFilamentProject::query()->findOrFail(7);
        $denied = ContractFilamentProject::query()->findOrFail(8);
        $allowed->members()->attach(1, ['role' => 'contract-project-editor']);
        GateWorld::serve(User::query()->findOrFail(1));

        $this->assertTrue(ContractFilamentProjectResource::canView($allowed));
        $this->assertFalse(ContractFilamentProjectResource::canView($denied));
        $this->assertSame([7], ContractFilamentProjectResource::getEloquentQuery()->orderBy('id')->pluck('id')->all());
        $allowed->members()->detach(1);
        $this->assertFalse(ContractFilamentProjectResource::canView($allowed));
        $this->assertSame([], ContractFilamentProjectResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_filament_tenant_context_inheritance_in_inherit_mode(): void
    {
        $this->exerciseFilamentTenantContext('inherit');
    }

    public function test_filament_tenant_context_inheritance_in_isolated_mode(): void
    {
        $this->exerciseFilamentTenantContext('isolated');
    }

    private function exerciseFilamentTenantContext(string $mode): void
    {
        $membership = new Membership;
        $tenant = TenantRef::of('team', 7);
        $definition = new ProjectDefinition(recordClass: ContractFilamentProject::class, resolvedTenant: $tenant);
        FilamentFixture::$definitions = FilamentDefinitions::Resources;
        FilamentFixture::$abilities = ['view_any'];
        FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
        FilamentFixture::$adminTenantPolicy = TenantPolicy::required(Team::class)->requireMembership($membership);
        FilamentFixture::$adminPlugins = [$mode === 'inherit' ? ScopesPlugin::inherit($definition) : ScopesPlugin::isolated($definition)];
        FilamentFixture::$pages = FilamentFixture::$widgets = [];
        $this->bootFilament();
        Schema::create('contract_filament_projects', static function (Blueprint $table): void {
            $table->id();
        });
        ContractFilamentProject::query()->insert([['id' => 7], ['id' => 8]]);
        $user = User::query()->findOrFail(1);
        $other = TenantRef::of('team', 8);
        $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view_any'))->inTenant($tenant);
        $otherRequest = $request->inTenant($other);
        $context = AssignmentScopeRef::of('project', 7);
        $scopedRequest = $request->inScope(AccessScope::in($tenant, $context));
        $otherContextRequest = $request->inScope(AccessScope::in($tenant, AssignmentScopeRef::of('project', 8)));
        $panel = app(PanelRegistry::class)->get('admin');
        $authorizer = app(Authorizer::class);
        $this->assertFalse($authorizer->decide($panel, $request)->allowed());
        AzGuard::panel('admin')->inTenant($tenant)->for($user)->grantPermission('orders.view_any');
        $this->assertTrue($authorizer->decide($panel, $request)->allowed());
        $this->assertSame($mode === 'inherit', $authorizer->decide($panel, $scopedRequest)->allowed());
        $this->assertSame($mode === 'inherit', $authorizer->decideMany([$scopedRequest])->get(0)->allowed());
        AzGuard::panel('admin')->inTenant($tenant)->for($user)->revokePermission('orders.view_any');
        AzGuard::panel('admin')->inTenant($tenant)->for($user)->grantPermission('orders.view_any', $context);
        $this->assertTrue($authorizer->decide($panel, $scopedRequest)->allowed());
        $batch = $authorizer->decideMany([$scopedRequest, $otherContextRequest, $otherRequest]);
        $this->assertTrue($batch->get(0)->allowed());
        $this->assertFalse($authorizer->decide($panel, $otherContextRequest)->allowed());
        $this->assertFalse($batch->get(1)->allowed());
        $this->assertFalse($batch->get(2)->allowed());
        $this->assertFalse($authorizer->decide($panel, $otherRequest)->allowed());
        // Both contexts are allowed before membership is revoked, so denial cannot be caused by scope alone.
        AzGuard::panel('admin')->inTenant($tenant)->for($user)->grantPermission('orders.view_any');
        $this->assertTrue($authorizer->decide($panel, $request)->allowed());
        $this->assertTrue($authorizer->decideMany([$request])->get(0)->allowed());
        $membership->members = [];
        $this->assertFalse($authorizer->decide($panel, $request)->allowed());
        $this->assertFalse($authorizer->decideMany([$request])->get(0)->allowed());
        $this->assertFalse($authorizer->decide($panel, $scopedRequest)->allowed());
        $this->assertFalse($authorizer->decideMany([$scopedRequest])->get(0)->allowed());
    }
}
