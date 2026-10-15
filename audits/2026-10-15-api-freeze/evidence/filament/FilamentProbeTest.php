<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Changes\GrantFilter;
use AzGuard\Filament\AzGuardPlugin;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use AzGuard\Filament\FilamentDefinitions;
use AzGuard\Filament\Internal\Editors\GrantEditor;
use AzGuard\Filament\Internal\Editors\TargetSelector;
use AzGuard\Filament\Resources\RoleResource\Pages\ViewRole;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\EditorWorld;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\GrantWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Audit-only probes. Assertions document observed defects; they are not production acceptance tests.
// Run with DB_CONNECTION=sqlite, DB_DATABASE=:memory:, AUTHORITY_REPLICA_TEST=.
final class FilamentProbeTest extends TestCase
{
    use BootsFilament;

    protected function tearDown(): void
    {
        parent::tearDown();
        FilamentFixture::reset();
    }

public function testReadOnlyGrantsAuthorizeAttach(): void
{
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));
    $owner = Order::query()->findOrFail(1);
    expect($owner->products()->whereKey(4)->exists())->toBeFalse();

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ListOrders::class])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => 4])
        ->assertHasNoActionErrors();

    expect($owner->products()->whereKey(4)->exists())->toBeTrue();
    expect(OrderResource::can('update', $owner))->toBeFalse();
}

public function testResourceWidgetEscapesEnforcement(): void
{
    GateWorld::prepare();
    FilamentFixture::$resources = [AuditWidgetResource::class];
    FilamentFixture::$widgets = [];
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
    $keys = AzGuardPlugin::get('admin')->keys(Filament::getPanel('admin'))->all();

    expect(array_map(fn ($key) => $key->class, $keys))->not->toContain(AuditUnprotectedWidget::class);
    Livewire::test(AuditWidgetList::class)->assertSuccessful()->assertSeeLivewire(AuditUnprotectedWidget::class);
    expect(AuditUnprotectedWidget::canView())->toBeTrue();
}

public function testResourceWidgetMissingFromCatalogue(): void
{
    FilamentFixture::$resources = [AuditWidgetResource::class];
    FilamentFixture::$widgets = [];
    FilamentFixture::$definitions = FilamentDefinitions::Resources;
    FilamentFixture::$guardPermissions = [FilamentSource::make('admin')];
    $this->bootFilament();

    expect(AzGuard::panel('admin')->catalog()->has('widgets.audit-protected-widget'))->toBeFalse();
}

public function testRoleViewNotRecheckedOnHydration(): void
{
    EditorWorld::prepare();
    $this->bootFilament();
    EditorWorld::seed();
    EditorWorld::editor(['azguard-roles.view_any', 'azguard-roles.view']);
    $page = Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'member'])->assertSuccessful();
    GateWorld::revoke('azguard-roles.view');

    $page->call('$refresh')->assertSuccessful()->assertSee('entry.enter')->assertSee('pages.*')
        ->assertSee(\AzGuard\Tests\Fixtures\Filament\Guards\GrantedMemberRole::class);
    Livewire::test(ViewRole::class, ['panel' => 'admin', 'role' => 'member'])->assertForbidden();
}

public function testRepeatedSubjectDirectoryQueries(): void
{
    GrantWorld::prepare();
    $this->bootFilament();
    GrantWorld::seed();
    $user = GrantWorld::editor();
    $access = TargetSelector::current()->access('admin');
    $keys = [];
    foreach ($access->schema()->permissions() as $permission) {
        if ($permission->grantable()) {
            $keys[] = $permission->key->local();
        }
    }
    expect(count($keys))->toBeGreaterThanOrEqual(25);
    $access->for($user)->grantPermission(array_slice($keys, 0, 25));
    $editor = GrantEditor::of($access, 'permission');
    $page = $editor->page(new GrantFilter(kind: 'permission', subject: SubjectRef::of('user', 1), limit: 25));
    expect($page->items)->toHaveCount(25);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/from\s+["`]users["`]/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
    foreach ($page->items as $record) {
        $editor->row($record);
    }

    expect($queries)->toHaveCount(40);
    expect(array_unique($queries))->toHaveCount(1);
}

}

final class AuditWidgetResource extends OrderResource
{
    protected static ?string $slug = 'orders';

    public static function getWidgets(): array
    {
        return [AuditUnprotectedWidget::class, AuditProtectedWidget::class];
    }

    public static function getPages(): array
    {
        return ['index' => AuditWidgetList::route('/')];
    }
}

final class AuditWidgetList extends ListRecords
{
    protected static string $resource = AuditWidgetResource::class;

    protected function getHeaderWidgets(): array
    {
        return [AuditUnprotectedWidget::class];
    }
}

final class AuditUnprotectedWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'azguard-fixtures::widget';
}

final class AuditProtectedWidget extends Widget
{
    use AuthorizesWidget;

    protected string $view = 'azguard-fixtures::widget';
}
