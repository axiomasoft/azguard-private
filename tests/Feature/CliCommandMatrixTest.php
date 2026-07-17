<?php

declare(strict_types=1);

use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * F19: Feature-matrix for CLI commands covering core command functionality.
 *
 * Test scope:
 * - guard:grants list (with filters, formats)
 * - guard:grant (permission assignment)
 * - guard:revoke-grant (permission revocation, with --all)
 * - guard:prune-grants (expired grant cleanup)
 * - guard:catalog (permission listing, JSON format)
 * - make:guard-* commands (code generation)
 * - guard:explain (permission resolution explanation)
 * - guard:abilities (user abilities listing)
 *
 * Acceptance criteria:
 *  - Each command exits with 0 on success, non-zero on failure
 *  - Config-swappable models are respected
 *  - Permission keys are validated against catalog
 *  - JSON/CSV output is parseable
 */

// ─── guard:grants list ──────────────────────────────────────────────────────

it('guard:grants lists all active direct grants', function (): void {
    $user = User::factory()->create();
    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
        'expires_at' => null,
    ]);

    $this->artisan('guard:grants')
        ->expectsOutputToContain('test.post.view')
        ->assertSuccessful();
});

it('guard:grants filters by user ID', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user1->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user2->id,
        'permission_key' => 'test.post.create',
        'panel_id' => 'test',
    ]);

    $this->artisan('guard:grants', ['--user' => $user1->id])
        ->expectsOutputToContain('test.post.view')
        ->assertSuccessful();
});

it('guard:grants filters by panel ID', function (): void {
    $user = User::factory()->create();

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'other.post.view',
        'panel_id' => 'other',
    ]);

    $this->artisan('guard:grants', ['--panel' => 'test'])
        ->expectsOutputToContain('test.post.view')
        ->assertSuccessful();
});

it('guard:grants outputs JSON format', function (): void {
    $user = User::factory()->create();
    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    // Use Artisan facade to access output directly
    $code = Artisan::call('guard:grants', ['--format' => 'json']);

    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()->not->toBeEmpty()
        ->and($payload[0])->toHaveKeys(['user_id', 'panel', 'permission_key']);
});

it('guard:grants outputs CSV format', function (): void {
    $user = User::factory()->create();
    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    $this->artisan('guard:grants', ['--format' => 'csv'])
        ->expectsOutputToContain('user_id,panel,permission_key,expires_at')
        ->assertSuccessful();
});

// ─── guard:grant ────────────────────────────────────────────────────────────

it('guard:grant assigns a permission to a user on a panel', function (): void {
    $user = User::factory()->create();

    $this->artisan('guard:grant', [
        'user-id' => $user->id,
        'permission' => 'test.post.view',
        'panel' => 'test',
    ])->assertSuccessful();

    $grant = DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->where('permission_key', 'test.post.view')
        ->where('panel_id', 'test')
        ->first();

    expect($grant)->not->toBeNull();
});

it('guard:grant can set a TTL (expiry) on the grant', function (): void {
    $user = User::factory()->create();

    $this->artisan('guard:grant', [
        'user-id' => $user->id,
        'permission' => 'test.post.view',
        'panel' => 'test',
        '--ttl' => '3600',
    ])->assertSuccessful();

    $grant = DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->where('permission_key', 'test.post.view')
        ->firstOrFail();
    expect($grant->expires_at)->not->toBeNull();
});

// ─── guard:revoke-grant ─────────────────────────────────────────────────────

it('guard:revoke-grant revokes a direct grant for a user', function (): void {
    $user = User::factory()->create();
    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    $this->artisan('guard:revoke-grant', [
        'user-id' => $user->id,
        'permission' => 'test.post.view',
        'panel' => 'test',
        '--force' => true,
    ])->assertSuccessful();

    expect(DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->where('permission_key', 'test.post.view')
        ->exists())->toBeFalse();
});

it('guard:revoke-grant is a no-op if the grant does not exist', function (): void {
    $user = User::factory()->create();

    $this->artisan('guard:revoke-grant', [
        'user-id' => $user->id,
        'permission' => 'test.post.view',
        'panel' => 'test',
        '--force' => true,
    ])->assertSuccessful();
});

it('guard:revoke-grant can revoke all grants for a user with --all', function (): void {
    $user = User::factory()->create();

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.create',
        'panel_id' => 'test',
    ]);

    $this->artisan('guard:revoke-grant', [
        'user-id' => $user->id,
        'permission' => 'ignored',
        'panel' => 'test',
        '--all' => true,
        '--force' => true,
    ])->assertSuccessful();

    expect(DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->count())->toBe(0);
});

// ─── guard:prune-grants ─────────────────────────────────────────────────────

it('guard:prune-grants removes expired grants', function (): void {
    $user = User::factory()->create();

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('guard:prune-grants')->assertSuccessful();

    expect(DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->count())->toBe(0);
});

it('guard:prune-grants does not remove active (non-expired) grants', function (): void {
    $user = User::factory()->create();

    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan('guard:prune-grants')->assertSuccessful();

    expect(DirectGrant::where('grantable_type', User::class)
        ->where('grantable_id', $user->id)
        ->count())->toBe(1);
});

// ─── guard:catalog ──────────────────────────────────────────────────────────

it('guard:catalog lists all registered permissions', function (): void {
    $this->artisan('guard:catalog')
        ->expectsOutputToContain('test.post.view')
        ->expectsOutputToContain('test.post.create')
        ->assertSuccessful();
});

it('guard:catalog outputs JSON format', function (): void {
    $code = Artisan::call('guard:catalog', ['--format' => 'json']);

    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray();
});

// ─── make:guard-permission ──────────────────────────────────────────────────

it('make:guard-permission generates a permission enum', function (): void {
    File::deleteDirectory(base_path('app/Guards'));

    $this->artisan('make:guard-permission', [
        'panel' => 'Admin',
        'domain' => 'Posts',
        '--path' => 'app/Guards',
        '--force' => true,
    ])->assertSuccessful();

    $path = base_path('app/Guards/Admin/Posts/Permissions/PostsPermission.php');
    expect($path)->toBeFile()
        ->and(File::get($path))->toContain('enum PostsPermission');

    File::deleteDirectory(base_path('app/Guards'));
});

// ─── make:guard-policy ──────────────────────────────────────────────────────

it('make:guard-policy generates a policy class with #[GuardPolicy]', function (): void {
    File::deleteDirectory(base_path('app/Guards'));

    $this->artisan('make:guard-policy', [
        'panel' => 'Admin',
        'domain' => 'Posts',
        '--path' => 'app/Guards',
        '--force' => true,
    ])->assertSuccessful();

    $path = base_path('app/Guards/Admin/Posts/Policies/PostsPolicy.php');
    expect($path)->toBeFile()
        ->and(File::get($path))->toContain('class PostsPolicy')
        ->and(File::get($path))->toContain('#[GuardPolicy');

    File::deleteDirectory(base_path('app/Guards'));
});

// ─── make:guard-abilities ───────────────────────────────────────────────────

it('make:guard-abilities generates an AbilitiesDto subclass', function (): void {
    File::deleteDirectory(base_path('app/Guards'));

    $this->artisan('make:guard-abilities', [
        'panel' => 'Admin',
        'domain' => 'Posts',
        '--path' => 'app/Guards',
        '--force' => true,
    ])->assertSuccessful();

    $path = base_path('app/Guards/Admin/Posts/Abilities/PostsAbilities.php');
    expect($path)->toBeFile()
        ->and(File::get($path))->toContain('class PostsAbilities extends AbilitiesDto')
        ->and(File::get($path))->toContain('protected static function abilityMap');

    File::deleteDirectory(base_path('app/Guards'));
});

// ─── guard:explain ──────────────────────────────────────────────────────────

it('guard:explain shows why a user has or lacks a permission', function (): void {
    $user = User::factory()->create();
    DirectGrant::create([
        'grantable_type' => User::class,
        'grantable_id' => $user->id,
        'permission_key' => 'test.post.view',
        'panel_id' => 'test',
    ]);

    $this->artisan('guard:explain', [
        'user' => $user->id,
        'ability' => 'test.post.view',
        '--panel' => 'test',
    ])
        ->expectsOutputToContain('test.post.view')
        ->assertSuccessful();
});

it('guard:explain explains a denied permission', function (): void {
    $user = User::factory()->create();

    $this->artisan('guard:explain', [
        'user' => $user->id,
        'ability' => 'test.post.create',
        '--panel' => 'test',
    ])
        ->expectsOutputToContain('test.post.create')
        ->assertSuccessful();
});

// ─── guard:abilities ────────────────────────────────────────────────────────

it('guard:abilities lists resolved abilities for a user', function (): void {
    $user = User::factory()->create();

    $this->artisan('guard:abilities', [
        'user' => $user->id,
        '--panel' => 'test',
    ])
        ->assertSuccessful();
});
