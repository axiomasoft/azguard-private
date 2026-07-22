# Testing

AzGuard is designed to be test-friendly. Roles are PHP classes — they're always available without seeding. Permission checks are pure functions — they're easy to assert.

## Setup

For feature tests that touch the database, use `RefreshDatabase` and register the service provider:

```php
use AzGuard\AzGuardServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AzGuardServiceProvider::class];
    }
}
```

## Creating users with roles

```php
public function test_editor_can_view_documents(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertOk();
}

public function test_viewer_cannot_delete(): void
{
    $user     = User::factory()->create();
    $document = Document::factory()->create();
    $user->assignRole('viewer');

    $this->actingAs($user)
        ->delete(route('documents.destroy', $document))
        ->assertForbidden();
}
```

## Testing permission checks directly

```php
public function test_editor_permissions(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->assertTrue($user->hasPermission(DocumentsPermission::View));
    $this->assertTrue($user->hasPermission(DocumentsPermission::Edit));
    $this->assertFalse($user->hasPermission(DocumentsPermission::Delete));

    // Check each permission individually
    $this->assertTrue($user->hasPermission(DocumentsPermission::Edit));
    $this->assertFalse($user->hasPermission(DocumentsPermission::Delete)); // editor doesn't have Delete
}
```

## Testing direct grants

```php
use AzGuard\Facades\AzGuard;

public function test_user_with_direct_grant_can_access(): void
{
    $user = User::factory()->create();

    AzGuard::forUser($user)
        ->on('app')
        ->grant(DocumentsPermission::View);

    $this->assertTrue($user->hasPermission(DocumentsPermission::View));
}

public function test_expired_grant_is_denied(): void
{
    $user = User::factory()->create();

    // Pass an explicit past expiry to the grant() method
    $user->grant(DocumentsPermission::View, 'app', now()->subMinute()); // already expired

    $user->flushPermissions();         // clear in-memory cache

    $this->assertFalse($user->hasPermission(DocumentsPermission::View));
}

public function test_grant_with_ttl_is_active(): void
{
    $user = User::factory()->create();

    AzGuard::forUser($user)
        ->on('app')
        ->ttl(3600)                    // 1 hour from now, in seconds
        ->grant(DocumentsPermission::Export);

    $this->assertTrue($user->hasPermission(DocumentsPermission::Export));
}
```

## Using Gate in tests

```php
public function test_gate_allows_editor(): void
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    $this->actingAs($user);

    // ✅ Gate uses the full, panel-prefixed permission key
    $this->assertTrue(Gate::allows('app.documents.view'));
    $this->assertTrue(Gate::allows('app.documents.edit'));
    $this->assertFalse(Gate::allows('app.documents.delete'));
}

public function test_gate_with_model(): void
{
    $user     = User::factory()->create();
    $document = Document::factory()->create(['owner_id' => $user->id]);
    $user->assignRole('editor');

    $this->actingAs($user);

    // Routes through DocumentPolicy::update() if registered
    $this->assertTrue(Gate::allows('app.documents.edit', $document));
}
```

## Testing services that check permissions

Testing a service against the real resolver — assign roles or grants and assert
through `hasPermission()` / `Gate::allows()` — works out of the box; keep the
cache store on `'array'` (the default) so nothing leaks between tests:

```php
public function test_service_checks_permission(): void
{
    $user = User::factory()->create();

    // Real grant — cache store='array' keeps it request-scoped
    $user->grant(DocumentsPermission::View, 'app');

    $result = app(DocumentService::class)->canView($user, $document);

    $this->assertTrue($result);
}

public function test_service_denies_without_permission(): void
{
    $user = User::factory()->create();
    // No permissions granted

    $this->assertFalse(
        app(DocumentService::class)->canView($user, $document)
    );
}
```

## Fakes: testing without a database

For unit tests that only touch the permission surface, AzGuard also ships a
dependency-free test double: `FakeAzGuardUser`. No migrations, panels or catalog
required:

```php
use AzGuard\Testing\FakeAzGuardUser;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

$user = (new FakeAzGuardUser)->grant('app', DocumentsPermission::View);

$user->hasPermission(DocumentsPermission::View); // true
$user->isSuperAdmin();                           // false

(new FakeAzGuardUser)->wildcard()->isSuperAdmin(); // true
```

Type-hint `HasPermissions` (or `Authenticatable`) where you accept the fake in the
adapter under test. It intentionally provides no roles/relations — use a real
Eloquent user with `HasAzGuard` when you need role behavior.

To grant a fixed set to **real** users without touching roles or DB rows, register
a `FakeGrantSource`. It sits above the built-in sources, so its grants win during
tests:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Testing\FakeGrantSource;

$fake = (new FakeGrantSource)->grant('app', DocumentsPermission::View);
app()->instance(FakeGrantSource::class, $fake);

AzGuard::registerGrantSource(FakeGrantSource::class);

// now any user passes:
$user->hasPermission(DocumentsPermission::View); // true

// (new FakeGrantSource)->wildcard() grants everything, like a super-admin
```

For a catalog-free setup, pair `FakeGrantSource` with a plain string key check —
no panel provider is required just to assert a permission:

```php
$user->hasPermission('app.documents.view'); // works with no panel registered
```

## `AzGuard::fake()` — recording grants and checks

`AzGuard::fake()` swaps the facade for a recording double (the `Event::fake()`/
`Pdf::fake()` pattern): grants and checks still run for real — fake() observes,
it does not replace behavior — so `assertGranted()`/`assertDenied()`/
`assertChecked()` read from what actually happened during the test:

```php
use AzGuard\Facades\AzGuard;

public function test_it_records_grants_and_checks(): void
{
    AzGuard::fake();

    $user = User::factory()->create();

    AzGuard::forUser($user)->on('app')->grant(DocumentsPermission::View);

    $this->assertTrue($user->can('app.documents.view'));

    AzGuard::assertGranted($user, DocumentsPermission::View, 'app');
    AzGuard::assertChecked('app.documents.view');
}
```

Each assertion also accepts a closure predicate over a `Recorded` (`user`,
`key`, `panelId`, `result`) instead of the simple form — there is no "get log"
method, only assertions:

```php
use AzGuard\Testing\Recorded;

AzGuard::assertGranted(fn (Recorded $r) => $r->key === 'app.documents.view');
AzGuard::assertChecked(fn (Recorded $r) => $r->key === 'app.documents.view' && $r->result === true);
```

See [Integration & Testing](/recipes/integration) for the segregated-contracts
pattern (`HasScopedRoles`/`HasDirectGrants` as opt-in contract+trait pairs) and
the optional context-guard visibility check.

## Pest syntax

```php
it('allows editors to view documents', function () {
    $user = User::factory()->create();
    $user->assignRole('editor');

    expect($user->hasPermission(DocumentsPermission::View))->toBeTrue();
    expect($user->hasPermission(DocumentsPermission::Delete))->toBeFalse();
});

it('forbids unauthenticated access', function () {
    $this->get(route('documents.index'))
        ->assertRedirect(route('login'));
});

it('returns 403 for users without permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('documents.index'))
        ->assertForbidden();
});
```

## User factories with roles

Define factory states so tests stay readable:

```php
// database/factories/UserFactory.php
public function editor(): static
{
    return $this->afterCreating(fn (User $user) =>
        $user->assignRole('editor')
    );
}

public function admin(): static
{
    return $this->afterCreating(fn (User $user) =>
        $user->assignRole('super-admin')
    );
}
```

```php
// Clean test setup
$editor = User::factory()->editor()->create();
$admin  = User::factory()->admin()->create();
```

## Asserting forbidden responses

```php
// Unauthenticated — redirected to login
$this->get(route('documents.index'))
    ->assertRedirect(route('login'));

// Authenticated but no permission — 403
$this->actingAs(User::factory()->create())
    ->get(route('documents.index'))
    ->assertForbidden();

// Correct role — passes
$this->actingAs(User::factory()->editor()->create())
    ->get(route('documents.index'))
    ->assertOk();
```

## Tips

- **Flush the permission cache between state changes** in a single test: `$user->flushPermissions()`.
- **Use `assertForbidden()` not `assertStatus(403)`** for readable test output.
- **Test both sides.** For every permission you assert as `true`, also test what a user *without* it sees.
- **Keep the array cache store in tests** — leave `cache.store` as `'array'` (the default) in `config/az-guard.php` so resolved permissions never cross request/test boundaries.
- **Reach for a fake first** for unit tests that only need a permission surface — `FakeAzGuardUser`/`FakeGrantSource` skip the database, panels and catalog entirely.
