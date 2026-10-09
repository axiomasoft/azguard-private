# Testing

AzGuard has no "allow everything" switch for tests. Tests grant real roles and permissions and run the real
engine, so they exercise the same policies, scopes and caches as production.

## Signing in with grants

```php
use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PostTest extends TestCase
{
    use InteractsWithAzGuard;
    use RefreshDatabase;

    public function test_an_editor_updates_own_posts(): void
    {
        $user = User::factory()->create();
        $this->actingAsWithRoles($user, [EditorRole::class]);

        $this->put("/admin/posts/{$ownPost->id}")->assertOk();
    }
}
```

| Helper | Grants | Admits to `azguard.panel` |
|---|---|---|
| `actingAsWithRoles($user, $roles, on:, panel:)` | Role grants | yes |
| `actingAsWithPermissions($user, $permissions, on:, panel:)` | Direct permission grants | no, because direct permissions do not admit |
| `actingAsSuperAdmin($user, panel:)` | The panel's `#[SuperAdmin]` role | yes |

- **How the helpers work.** Each helper stores the grants through the change pipeline, with the system actor
  `testing`. It then signs the user in on the panel's auth guard and returns `$this`.
- **Tenants.** For a panel with tenants, select the tenant before the call, as for any grant.
- **Inside a test.** You can also grant directly with `AzGuard::actingAs('test', fn () => $user->grantRole('editor'))`.

### RefreshDatabase

`RefreshDatabase` wraps each test in a transaction, and checks inside an application transaction are denied
(see [Decisions](/concepts/decisions#checks-inside-database-transactions)). `InteractsWithAzGuard`
recognizes Laravel's test transaction, so checks work. **Use the trait in every test that checks
permissions under `RefreshDatabase`.**

A transaction your test opens on top of that, such as `DB::transaction()` in the code under test, is still
denied. That is the same behavior as production.

### Changing the user in a test

A check on a model uses that instance, so a change written past it is not seen, just as `Auth::user()` does
not see it:

```php
User::whereKey($user->id)->update(['active' => false]);

$user->hasPermission(PostPermission::View);            // still uses active = true
$user->refresh()->hasPermission(PostPermission::View); // reads the row again
```

Grants need no such step: `grantRole()`, `revokeRole()` and the other change methods are seen by the next check.

## Recording checks and changes

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\Effect;

$fake = AzGuard::fake();

// ... run the code under test ...

$fake->assertChecked(PostPermission::View, times: 1);
$fake->assertNotChecked(PostPermission::Create);
$fake->assertDecided($user, PostPermission::Delete, Effect::Deny);
$fake->assertRoleGranted($user, EditorRole::class);
$fake->assertRoleRevoked($user, 'editor', on: $team);
$fake->assertPermissionGranted($user, PostPermission::Delete);
$fake->checks();    // list<RecordedCheck>
$fake->changes();   // list<RecordedChange>
$fake->stop();
```

The fake **records only**. It decides nothing and replaces nothing: a denied permission stays denied. It keeps
recording when the application calls `Event::fake()`.

## Asserting events

The change events are ordinary Laravel events:

```php
Event::fake([RoleGranted::class]);

AzGuard::actingAs('seeder', fn () => $user->grantRole('editor'));

Event::assertDispatched(RoleGranted::class, fn (RoleGranted $e) => $e->role->key() === 'editor'
    && $e->actor?->reason === 'seeder');
```

## Grants in memory

`AzGuard\Testing\FakeSource` is an in-memory source. The test states who holds what, and the real engine decides,
with scope, expiry and role rules. Attach it to a panel in a test-only configuration, for example through
`AzGuard::configurePanel()`, when you do not want database grants.

## Contract tests for extensions

If you write a source, hook, restriction, plugin or resolver, use the matching trait from
`AzGuard\Testing\Contracts`. Each trait checks your class against the guarantees the engine relies on, such as
stable ids, answering only for its own panel, not reading the clock and writing only inside the change
transaction:

- `SourceContractTests`
- `HookContractTests`
- `RestrictionContractTests`
- `PluginContractTests`
- `SubjectResolverContractTests`
- `AssignmentScopeResolverContractTests`

```php
final class LdapSourceTest extends TestCase
{
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return new LdapSource(/* ... */);
    }
}
```
