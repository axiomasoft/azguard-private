# Checking access

All of these reach the same [decision pipeline](/concepts/decisions). Pick the one that fits the call site.

## On the model

```php
$user->hasPermission(PostPermission::Update, $post);   // resource or scope as the second argument
$user->hasAnyPermission([PostPermission::Update, PostPermission::Delete], $post);
$user->hasAllPermissions([PostPermission::View, PostPermission::Update]);

$user->hasRole('editor');                              // a key, a role class, or an array (any of them)
$user->hasAnyRole(['editor', 'author']);
$user->hasAllRoles(['editor', 'author']);
$user->isSuperAdmin();

$user->permissionNames();   // Collection of granted names, for example 'admin.posts.view'
$user->roleNames();         // Collection of role keys
```

- **The panel.** Every method takes `guard:` (a panel id) as its last argument. `$user->guard('admin')`
  returns a `SubjectAccess` bound to one panel. It has the same methods plus `decide()`, `abilities()`,
  `roleGrants()` and `permissionGrants()`.
- **The second argument (`on:`)** is a resource model, which is passed to policies and resolved to its scope.
  It can also be an assignment scope (a model or `AssignmentScopeRef`). See
  [Tenants and scopes](/guides/tenants-and-scopes).
- **Introspection, not authorization.** `permissionNames()` and `permissionSet()` list the grants the subject
  holds. They do not run policies or restrictions, so `hasPermission()` stays the answer to "may they?".

## Facade

```php
use AzGuard\Facades\AzGuard;

AzGuard::check($user, PostPermission::Update, $post);       // bool
AzGuard::authorize($user, PostPermission::Update, $post);   // throws AuthorizationException on deny
AzGuard::panel('admin')->for($user)->decide(PostPermission::Update, $post); // Decision
```

`$subject` may be a model, an `Authenticatable` or a `SubjectRef::of('user', 1)`.

## Laravel Gate

AzGuard registers a `Gate::before` callback, so `can()`, `@can`, `Gate::allows()`, `$this->authorize()` and
`authorize` in form requests all see panel permissions:

```php
$user->can('admin.posts.update', $post);   // prefixed name
$user->can('admin:posts.update', $post);   // full name
$user->can('update', $post);               // a word plus a model of #[Resource(model:)]
$user->can('viewAny', Post::class);        // a word plus a model class
Gate::inspect('admin.posts.delete', $post)->message();
```

```blade
@can('update', $post)
    <a href="{{ route('posts.edit', $post) }}">Edit</a>
@endcan
```

The rules:

- **Owned abilities.** A name of a registered panel, or a word that matches exactly one `#[Resource(model:)]`
  permission, belongs to AzGuard. Its answer is final (`gate.mode = authoritative`): a denied permission is
  not rescued by a Laravel policy.
- **Foreign abilities.** Any other ability returns `null` from AzGuard. Laravel continues with your own
  `Gate::define()` and policies as usual.
- **Callbacks registered earlier.** A `Gate::before` registered before AzGuard that returns `true` wins.
  Register such callbacks with care.
- **Turning the bridge off.** Set `azguard.gate.enabled = false`. The facade and the middleware keep working.

## Routes

```php
Route::middleware(['auth', 'azguard.panel:admin'])->prefix('admin')->group(function () {
    Route::get('/posts', [PostController::class, 'index'])->middleware('azguard.can:posts.view_any');
    Route::put('/posts/{post}', [PostController::class, 'update'])->middleware('azguard.can:posts.update,post');
});
```

### `azguard.panel:{id}`

This middleware enters the panel for the rest of the request:

- It runs the panel's `middleware([...])` that the route does not already run.
- It makes the panel current, so short names such as `posts.view` resolve in it, and sets the tenant and
  scope chosen by the panel's resolvers.
- It **admits** only accepted subjects that hold at least one role of the panel. The role can be stored,
  automatic, from a relation, or super admin. Direct permissions alone do not admit. If the panel has an
  `entry($permission)`, that permission is required as well.
- A guest gets the normal authentication response. A denied user gets the panel's `onDenied()` response, or
  403 by default.
- Queued jobs dispatched meanwhile carry the panel id as a hint.

### `azguard.can:{permission}[,{parameter}]`

This middleware checks one permission. The optional second argument names the route parameter that holds the
resource or scope. A guest is denied. A denial is a 403 `AuthorizationException` without details.

### Controller attributes

```php
use AzGuard\Attributes\CheckPermission;
use AzGuard\Attributes\SkipPermissionCheck;

#[CheckPermission(PostPermission::ViewAny, only: ['index'])]
final class PostController
{
    public function index() { /* ... */ }

    #[CheckPermission(PostPermission::Update, on: 'post', message: 'You cannot edit this post.')]
    public function update(Post $post) { /* ... */ }

    #[SkipPermissionCheck]
    public function preview() { /* ... */ }
}
```

`#[CheckPermission]` adds `azguard.can`. Laravel 13 applies it as controller middleware. On Laravel 11 and 12,
`azguard.panel` applies it. With `->requireRouteChecks()` on the panel, an action with neither attribute throws
`MissingPermissionCheckException` in `local` and `testing` and answers 403 elsewhere.

## Frontend abilities

```php
$user->guard('admin')->abilities([PostPermission::View, PostPermission::Delete], $post);
// ['admin.posts.view' => true, 'admin.posts.delete' => false]
```

`azguard:make:permission ... --abilities` generates a typed DTO per group:

```php
PostAbilities::for($user->guard('admin'), $post);
// PostAbilities { viewAny: false, view: true, create: false, update: true, delete: false }
```

Share it with Inertia or return it from an API resource. Every flag is a full decision, policies included.

## Queues and the console

- **Panel.** A queued job does not inherit the request's tenant or scope. Pass them explicitly and use
  `$user->inTenant($tenant)` or `on:`. The panel id travels as a hint only.
- **Actor.** In the console, changes have the system actor unless you wrap them in `AzGuard::actingAs()`.
