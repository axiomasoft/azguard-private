<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\FilamentTenantResolver;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Http\EntryPermission;
use AzGuard\Tests\Fixtures\Http\EntryPolicy;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Closure;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\LivewireServiceProvider;

/**
 * The CRM stand with Filament: the Filament panel `crm` over the guard panel `crm`, whose tenant is the organization
 * Filament chose. Calls of clients are recorded in `client_calls`.
 */
trait BootsCrmFilament
{
    protected function getPackageProviders($app): array
    {
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
            CrmFilamentProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        View::addNamespace('azguard-fixtures', __DIR__.'/../../Filament/views');
        $app['config']->set('auth.providers.users.model', CrmFilamentUser::class);
        $app['config']->set('app.debug', false);
        $app->bind(Authenticatable::class, CrmFilamentUser::class);
    }

    /**
     * The guard panel `crm` with the tenant of Filament, the permissions of the Filament panel, the policy-only entry of
     * the HTTP stand and the explicit project mapping of clients that exact visibility needs; then the tables of calls
     * and exports.
     */
    protected static function crmFilament(?Closure $configure = null): void
    {
        CrmWorld::compile(static function (PanelBuilder $panel) use ($configure): void {
            // The authenticated model of Filament is a subject of the panel too, of the same type crm.user.
            $panel->for(model: CrmFilamentUser::class, guard: 'web')->tenantResolvers([new FilamentTenantResolver])
                ->permissions([CrmFilamentPermission::class, CrmEditorPermission::class, EntryPermission::class])->policies([EntryPolicy::class]);

            if ($configure !== null) {
                $configure($panel);
            }
        }, exact: true);
        // compile() forgets the scoped services, the manager of Filament among them; facades must not keep the old one.
        Facade::clearResolvedInstances();
        EntryPolicy::$allow = true;

        if (! Schema::hasTable('client_calls')) {
            Schema::create('client_calls', function (Blueprint $table): void {
                $table->foreignId('client_id');
                $table->foreignId('user_id');
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
        }
    }

    /** Makes a user a member of an organization without any role there. */
    protected static function member(int $user, int $organization = 1): void
    {
        DB::table('organization_user')->insertOrIgnore(['organization_id' => $organization, 'user_id' => $user]);
    }

    /**
     * What a request to /crm/{organization} sets up before a Livewire component runs: the user, the Filament panel and
     * tenant, and, as `azguard.panel:crm` does, the guard panel and its tenant.
     */
    protected function serveCrm(int $user, int $organization = 1): CrmFilamentUser
    {
        $member = CrmFilamentUser::query()->findOrFail($user);
        $this->actingAs($member);
        Filament::setCurrentPanel('crm');
        Filament::setTenant(Organization::query()->findOrFail($organization), isQuiet: true);
        Filament::bootCurrentPanel();
        $panel = app(PanelRegistry::class)->get('crm');
        app(CurrentPanel::class)->set($panel);
        app(CurrentContext::class)->set($panel, AzGuard::panel('crm')->inTenant(TenantRef::of('crm.organization', $organization))->scope());

        return $member;
    }
}
