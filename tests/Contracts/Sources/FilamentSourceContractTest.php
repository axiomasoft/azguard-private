<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
}
