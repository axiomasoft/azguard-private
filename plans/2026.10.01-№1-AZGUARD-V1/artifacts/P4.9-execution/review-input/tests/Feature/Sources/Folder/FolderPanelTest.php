<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Sources\Folder\PanelDiscovery;
use AzGuard\Tests\Fixtures\Guards\Admin\AdminGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Duo\FirstPermission;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Orders\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Admin\Permissions\Sources\SourcePermission;
use AzGuard\Tests\Fixtures\Guards\Admin\PluginsGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Admin\Policies\Duo\FirstPolicy;
use AzGuard\Tests\Fixtures\Guards\Admin\Policies\Duo\SecondPolicy;
use AzGuard\Tests\Fixtures\Guards\Admin\Policies\Orders\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Admin\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Guards\Admin\Roles\OverrideRole;
use AzGuard\Tests\Fixtures\Guards\Admin\Roles\SuperRole;
use AzGuard\Tests\Fixtures\Guards\Admin\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Guards\Admin\Sources\LdapSource;
use AzGuard\Tests\Fixtures\Guards\Ambiguous\AmbiguousGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Bare\BareGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Binding\BindingGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Both\BothGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Missing\MissingGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Plugins\Desk\Policies\Orders\DeskOrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Plugins\Support\Policies\Orders\SupportOrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Shared\Sources\SharedLdapSource;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\BlogFolderPlugin;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Permissions\Posts\PostPermission;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Policies\Posts\PostPolicy;
use AzGuard\Tests\Fixtures\Modules\Blog\Guards\Roles\BlogEditorRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;

uses(BootsWithCatalogCache::class);

beforeEach(function (): void {
    FixturePanel::reset();
    PanelDiscovery::$parsed = 0;
    self::$catalogCachePath = sys_get_temp_dir().'/azguard-folder-'.bin2hex(random_bytes(6)).'/azguard.php';
    self::$catalogBuildId = 'folder-build';
});

afterEach(function (): void {
    if (is_file(self::$catalogCachePath)) {
        @unlink(self::$catalogCachePath);
    }

    @rmdir(dirname(self::$catalogCachePath));
});

it('reads the panel folder into the catalog and does not scan what is not a permission group', function (): void {
    $this->bootPanels(['providers' => [AdminGuardPanelProvider::class]]);

    $registry = app(PanelRegistry::class);
    $catalog = $registry->catalog('admin');
    $view = $catalog->get('orders.view');
    $refund = $catalog->get('orders.refund');

    expect(array_map(static fn ($description) => $description->id, $registry->get('admin')->sources())[0])->toBe('folder')
        ->and($view->authority)->toBe(PermissionAuthority::Grants)
        ->and($view->label)->toBe('View list')
        ->and($view->group)->toBe('Orders')
        ->and($view->resourceModel)->toBe(User::class)
        ->and($view->case)->toBe(OrderPermission::View)
        ->and($refund->authority)->toBe(PermissionAuthority::Policy)
        ->and($refund->group)->toBe('refunds')
        ->and($refund->description)->toBe('Money back')
        ->and($catalog->has('orders.line.refund'))->toBeTrue()
        ->and($catalog->bindings()['orders.refund'])->toBe(OrderPolicy::class)
        ->and($catalog->bindingMethod('orders.refund'))->toBe('anyName')
        ->and($catalog->bindingMethod('orders.line.refund'))->toBe('line')
        ->and($catalog->bindingMethod('orders.view'))->toBeNull()
        ->and($catalog->has('users.view'))->toBeTrue()
        ->and($catalog->has('folder.sources.view'))->toBeTrue()
        ->and($catalog->get(SourcePermission::View->value)->group)->toBe('Sources')
        ->and($catalog->has('sources.trap.view'))->toBeFalse()
        ->and($catalog->has('resources.trap.view'))->toBeFalse()
        ->and($catalog->bindings()['duo.first.view'])->toBe(FirstPolicy::class)
        ->and($catalog->bindings()['duo.second.view'])->toBe(SecondPolicy::class)
        ->and(array_keys($catalog->roles()))->toEqualCanonicalizing(['manager', 'super', 'from-method'])
        ->and($catalog->roles()['manager']['permissions'])->toBe(['orders.view'])
        ->and($catalog->roles()['manager']['class'])->toBe(ManagerRole::class)
        ->and($catalog->roles()['super']['super_admin'])->toBeTrue()
        ->and($catalog->roles()['from-method']['class'])->toBe(OverrideRole::class)
        ->and((new ManagerRole)->level())->toBe(10)
        ->and((new ManagerRole)->label())->toBe('Manager')
        ->and((new OverrideRole)->label())->toBe('Method')
        ->and((new OverrideRole)->level())->toBe(4)
        ->and((new SuperRole)->superAdmin())->toBeTrue()
        ->and(array_keys($registry->all()))->toBe(['admin'])
        ->and(AzGuard::sources()->make('guard-ldap', 'admin'))->toBeInstanceOf(LdapSource::class)
        ->and(AzGuard::sources()->make('shared-ldap', 'admin'))->toBeInstanceOf(SharedLdapSource::class)
        ->and($registry->forEnum(OrderPermission::class))->toHaveCount(1)
        ->and($registry->forEnum(FirstPermission::class))->toHaveCount(1);
});

it('keeps Orders of two plugins apart and uses the cached discovery on the next boot', function (): void {
    $this->bootPanels(['providers' => [PluginsGuardPanelProvider::class]]);
    $live = app(PanelRegistry::class)->catalog('admin');

    expect($live->bindings()['support.orders.view'])->toBe(SupportOrderPolicy::class)
        ->and($live->bindings()['desk.orders.view'])->toBe(DeskOrderPolicy::class)
        ->and($live->bindings()['orders.refund'])->toBe(OrderPolicy::class)
        ->and($live->has('orders.view'))->toBeTrue();

    $this->artisan('azguard:catalog:cache')->assertSuccessful();
    $file = require self::$catalogCachePath;

    expect($file['panels']['admin']['discovery']['granted_to_all'])->toContain('orders.view')
        ->and($file['panels']['admin']['discovery']['scopes'])->toContain(ProjectScope::class);

    PanelDiscovery::$parsed = 0;
    $this->bootPanels(['providers' => [PluginsGuardPanelProvider::class]]);
    $cached = app(PanelRegistry::class)->catalog('admin');

    expect(PanelDiscovery::$parsed)->toBe(0)
        ->and($cached->snapshot())->toBe($live->snapshot())
        ->and($cached->bindingMethod('orders.refund'))->toBe('anyName');
});

it('rejects a group with several candidates, a missing mode, both modes, a role without a key, and a binding whose method is gone', function (): void {
    expect(fn () => $this->bootPanels(['providers' => [AmbiguousGuardPanelProvider::class]]))
        ->toThrow(InvalidPolicyStructureException::class, 'OnePermission.php')
        ->and(fn () => $this->bootPanels(['providers' => [MissingGuardPanelProvider::class]]))
        ->toThrow(DefinitionException::class, 'no authority')
        ->and(fn () => $this->bootPanels(['providers' => [BothGuardPanelProvider::class]]))
        ->toThrow(DefinitionException::class, 'both authority')
        ->and(fn () => $this->bootPanels(['providers' => [BareGuardPanelProvider::class]]))
        ->toThrow(InvalidRoleKeyException::class, 'no key')
        ->and(fn () => $this->bootPanels(['providers' => [BindingGuardPanelProvider::class]]))
        ->toThrow(DefinitionException::class, 'exactly one');
});

it('discovers a module folder without prefixing its permission or its role', function (): void {
    AdminPanel::describe(static fn (PanelBuilder $panel): PanelBuilder => $panel->plugins([BlogFolderPlugin::make()]));
    $this->bootPanels(['providers' => [AdminPanel::class]]);

    $registry = app(PanelRegistry::class);
    $catalog = $registry->catalog('admin');
    $resolver = app(PanelResolver::class);

    expect($catalog->has('blog.posts.edit'))->toBeTrue()
        ->and($catalog->has('blog.blog.posts.edit'))->toBeFalse()
        ->and($catalog->keyOf(PostPermission::Edit)->full())->toBe('admin:blog.posts.edit')
        ->and($catalog->roles()['blog-editor']['class'])->toBe(BlogEditorRole::class)
        ->and($catalog->roles()['blog-editor']['permissions'])->toBe(['blog.posts.edit'])
        ->and($catalog->bindings()['blog.posts.edit'])->toBe(PostPolicy::class)
        ->and($resolver->resolve(permission: PostPermission::Edit)['panel']->id())->toBe('admin')
        ->and($resolver->resolve(permission: 'admin.blog.posts.edit')['key']?->full())->toBe('admin:blog.posts.edit')
        ->and($registry->forEnum(PostPermission::class))->toHaveCount(1);
});
