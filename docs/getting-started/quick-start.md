# Quick start

This page takes a fresh Laravel application from zero to a permission enum, a role, a policy, a protected
route and a test. Every snippet below was run against Laravel 13 with the generated files.

It assumes you finished [Installation](/getting-started/installation): a panel `Admin` exists, `User` uses
`HasAzGuard`, and the morph alias `user` is registered.

## 1. The panel

`azguard:install --panel=Admin` generated the panel provider:

```php
// app/Guards/Admin/AdminGuardPanelProvider.php
final class AdminGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->for(User::class)                        // who can hold roles in this panel
            ->permissions([DatabaseSource::make()]);  // grants are stored in the azg_ tables
    }
}
```

The panel picks up `Permissions/`, `Policies/` and `Roles/` next to this file on its own.

## 2. Permissions

```bash
php artisan azguard:make:permission Admin Posts --model='App\Models\Post' --policy
```

```php
// app/Guards/Admin/Permissions/Posts/PostPermission.php
#[Resource(label: 'Posts', model: Post::class)]
#[RequiresGrant]
enum PostPermission: string
{
    #[Describe('View list')]
    case ViewAny = 'posts.view_any';

    #[Describe('View')]
    case View = 'posts.view';

    #[Describe('Create')]
    case Create = 'posts.create';

    #[Describe('Update')]
    case Update = 'posts.update';

    #[Describe('Delete')]
    case Delete = 'posts.delete';
}
```

The full name of a case is prefixed with the panel id: `PostPermission::Update` is `admin.posts.update`.
`#[RequiresGrant]` means that only a grant (through a role or directly) can allow it.

## 3. A policy that can veto

The generated `PostPolicy` has one `#[Decides]` method per case. Each method returns `true`, which means "no
objection". Restrict updates to the author:

```php
// app/Guards/Admin/Policies/Posts/PostPolicy.php
#[Decides(PostPermission::Update)]
public function update(Model $user, mixed $resource = null): bool
{
    return $resource === null || $resource->user_id === $user->getKey();
}
```

A policy can only veto. It is called after a grant was found, never instead of one.

## 4. A role

```bash
php artisan azguard:make:role Admin Editor
```

```php
// app/Guards/Admin/Roles/EditorRole.php
#[Role('editor', label: 'Editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [PostPermission::View, PostPermission::Update];
    }
}
```

The key `editor` is what the database stores. Keep it stable. The class name can change freely.

## 5. Grant and check

```php
use AzGuard\Facades\AzGuard;

AzGuard::actingAs('seeder', fn () => $user->grantRole(EditorRole::class));

$user->hasRole('editor');                          // true
$user->hasPermission(PostPermission::View);        // true
$user->hasPermission('admin.posts.view');          // true, the same permission as a string
$user->hasPermission(PostPermission::Update, $own);     // true
$user->hasPermission(PostPermission::Update, $foreign); // false: PostPolicy vetoes
$user->hasPermission(PostPermission::Delete);      // false: not granted

$user->can('update', $own);                        // true, through Laravel's Gate
$user->permissionNames()->all();                   // ['admin.posts.update', 'admin.posts.view']
```

- **The actor.** `AzGuard::actingAs()` names who makes the change. Without it, the actor is the authenticated
  user, or the system in the console.
- **The CLI.** The same grant works from the command line: `php artisan azguard:roles:grant 1 editor`.

## 6. Protect routes

```php
// routes/web.php
Route::middleware(['auth', 'azguard.panel:admin'])->group(function () {
    Route::get('/admin/posts', [PostController::class, 'index'])
        ->middleware('azguard.can:posts.view');
    Route::put('/admin/posts/{post}', [PostController::class, 'update'])
        ->middleware('azguard.can:posts.update,post'); // checks the permission on the {post} model
});
```

- `azguard.panel:admin` admits only users that hold at least one role of the panel. It also makes `admin` the
  current panel, so short names like `posts.view` resolve in it.
- `azguard.can` checks one permission.
- [Checking access](/guides/checking-access) shows the controller attributes and Blade.

## 7. Test it

```php
use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PostAccessTest extends TestCase
{
    use InteractsWithAzGuard;
    use RefreshDatabase;

    public function test_an_editor_updates_only_own_posts(): void
    {
        $user = User::factory()->create();
        $this->actingAsWithRoles($user, [EditorRole::class]);

        $this->put('/admin/posts/'.Post::create(['user_id' => $user->id])->id)->assertOk();
        $this->put('/admin/posts/'.Post::create(['user_id' => 999])->id)->assertForbidden();
    }
}
```

`actingAsWithRoles()` stores real grants through the same pipeline as production and signs the user in. Use a
role here: `azguard.panel` admits only role holders. `actingAsWithPermissions()` grants permissions directly,
which is enough for routes and code without `azguard.panel`. See [Testing](/guides/testing).

## 8. Diagnose

```bash
php artisan azguard:doctor                         # configuration, panels, storages, sources
php artisan azguard:permissions:show 1             # roles and permissions of user 1
php artisan azguard:explain user:1 posts.delete    # every stage of one decision
```

## Next

- [Panels](/concepts/panels), [Permissions](/concepts/permissions), [Roles](/concepts/roles) and
  [Policies](/concepts/policies) explain the model.
- [How a decision is made](/concepts/decisions) explains the order of the checks and why AzGuard fails closed.
