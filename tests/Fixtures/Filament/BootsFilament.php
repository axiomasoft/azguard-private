<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament;

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Filament\Guards\AdminGuardPanel;
use AzGuard\Tests\Fixtures\Filament\Guards\BackofficeGuardPanel;
use AzGuard\Tests\Fixtures\Filament\Guards\ReadonlyGuardPanel;
use AzGuard\Tests\Fixtures\Filament\Guards\SellerGuardPanel;
use AzGuard\Tests\Fixtures\Filament\Guards\TeamsGuardPanel;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Team;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Panels\AdminFilamentProvider;
use AzGuard\Tests\Fixtures\Filament\Panels\BackofficeFilamentProvider;
use AzGuard\Tests\Fixtures\Filament\Panels\PlainFilamentProvider;
use AzGuard\Tests\Fixtures\Filament\Panels\TenantedFilamentProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\LivewireServiceProvider;

/**
 * Boots the application with Filament, the fixture Filament panels `admin` and `backoffice` and the AzGuard panels
 * `admin`, `backoffice`, `seller`, `teams` and the read-only `readonly`, the Filament panel `tenanted` over `teams` and the Filament panel `plain`
 * without the plugin.
 *
 * Change `FilamentFixture` and call `bootFilament()` to boot the application again with other panels.
 */
trait BootsFilament
{
    public function setUpBootsFilament(): void
    {
        $this->createFixtureTables();
    }

    public function tearDownBootsFilament(): void
    {
        Relation::morphMap([], false);
    }

    /** Boots the application again with what `FilamentFixture` holds now; the fixture tables are created again. */
    protected function bootFilament(): void
    {
        $this->reloadApplication();
    }

    protected function createFixtureTables(): void
    {
        Schema::dropAllTables();
        app(StorageSchema::class)->create('default');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->nullable();
            $table->boolean('secret')->default(false);
            $table->boolean('locked')->default(false);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('secret')->default(false);
        });
        Schema::create('order_product', function (Blueprint $table): void {
            $table->foreignId('order_id');
            $table->foreignId('product_id');
        });
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_disk');
            $table->string('file_name')->nullable();
            $table->string('exporter');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->foreignId('user_id');
            $table->timestamps();
        });
        User::query()->insert([['id' => 1, 'name' => 'Member'], ['id' => 2, 'name' => 'Outsider']]);
        Order::query()->insert([['id' => 1, 'number' => 'A-1']]);
        Team::query()->insert([['id' => 7, 'name' => 'Seven']]);
    }

    protected function getPackageProviders($app): array
    {
        FilamentFixture::ensure();

        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            ...parent::getPackageProviders($app),
            AdminFilamentProvider::class,
            BackofficeFilamentProvider::class,
            TenantedFilamentProvider::class,
            PlainFilamentProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        Relation::morphMap(['user' => User::class, 'team' => Team::class], false);
        View::addNamespace('azguard-fixtures', __DIR__.'/views');

        $app['config']->set('auth.providers.users.model', User::class);

        if (FilamentFixture::$catalogCachePath !== null) {
            $app['config']->set('azguard.catalog.cache_path', FilamentFixture::$catalogCachePath);
            $app['config']->set('azguard.catalog.build_id', 'filament-fixture');
        }

        $app['config']->set('azguard.panels', ['providers' => [AdminGuardPanel::class, BackofficeGuardPanel::class, SellerGuardPanel::class, TeamsGuardPanel::class, ReadonlyGuardPanel::class]]);
    }
}
