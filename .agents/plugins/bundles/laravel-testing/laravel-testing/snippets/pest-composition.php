<?php
// Source: anonymized production project

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 1. Reused setUp-trait (composition instead of inheritance)
|    tests/Support/Concerns/ActsAsUser.php
|--------------------------------------------------------------------------
| Instead of inflating TestCase or create subclasses for each
| access script, behavior is packaged in a trait. TestCase connects
| just what the suite needs: `use ActsAsUser;` — and helpers are available in
| all Pest-closures (they are bound to the class TestCase).
*/

namespace Tests\Support\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait ActsAsUser
{
    /**
     * Creates a user and assigns a role.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUserWithRole(UserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * Authorizes a user with a role and returns a model, a common test prefix.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsRole(UserRole $role, array $attributes = []): User
    {
        $user = $this->makeUserWithRole($role, $attributes);
        $this->actingAs($user);

        return $user;
    }
}

/*
|--------------------------------------------------------------------------
| Second fixture trait: SeedsUserRoles — narrow responsibility, no duplication
| general DatabaseSeeder; is connected only in suites tied to roles.
|    tests/Support/Concerns/SeedsUserRoles.php
|--------------------------------------------------------------------------
*/

namespace Tests\Support\Concerns;

use Database\Seeders\UserRolesSeeder;
use Illuminate\Support\Facades\Artisan;

trait SeedsUserRoles
{
    protected function seedUserRoles(): void
    {
        Artisan::call('db:seed', ['--class' => UserRolesSeeder::class]);
    }
}

/*
|--------------------------------------------------------------------------
| 2. TestCase = composition of traits (not deep inheritance hierarchy)
|    tests/TestCase.php
|--------------------------------------------------------------------------
| TestCase remains subtle: connects fixture traits and holds guard
| DB isolation. New behavior is added by a new trait, not a new one
| subclass TestCase.
*/

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Concerns\ActsAsUser;
use Tests\Support\Concerns\SeedsUserRoles;

abstract class TestCase extends BaseTestCase
{
    use ActsAsUser;
    use SeedsUserRoles;

    // guard test database isolation - see section «Test database isolation»
}

/*
|--------------------------------------------------------------------------
| 3. tests/Pest.php — single «environmental hygiene» via beforeEach,
|    bound to directories, not repeated in each file
|--------------------------------------------------------------------------
| Time and network determinism is set ONCE per suite:
|   - Http::preventStrayRequests() — any unadulterated HTTP-call = fall
|     test (and not a silent march to the outer API)
|   - Sleep::fake() — sleep() in the code does not slow down the run
|   - freezeTime() — Carbon::now() frozen, time assertions stable
|   - Str::createRandomStringsNormally()/createUuidsNormally() — reset
|     possible fake from the previous test (insulation)
*/

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

// Feature: full database (RefreshDatabase) + network fakes/time.
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();

        Http::fake([
            // dev-asset server: shut down so as not to create more stray-requests
            '127.0.0.1:5173/*' => Http::response(''),
        ]);
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature');

// Unit: the same network fakes/time, but WITHOUT RefreshDatabase (pure logic).
pest()->extend(Tests\TestCase::class)
    ->beforeEach(function () {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| 4. Custom expectations and helpers - expand API, do not inherit classes
|--------------------------------------------------------------------------
*/

use App\Enums\OrderStatus;

expect()->extend('toHaveOrderStatus', function (OrderStatus $status) {
    return $this->toHaveProperty('status', $status);
});

/*
|--------------------------------------------------------------------------
| 5. Dataset: one test script × many inputs via ->with()
|    tests/Feature/Order/OrderEndpointAccessTest.php
|--------------------------------------------------------------------------
| Parameterization instead of copy-pasting the test body. Named dataset() does
| crash output is readable («with data set "orders.cancel"»). Each
| line - [route, payload]; there is one test body.
*/

dataset('order_guest_endpoints', [
    'cancel'   => ['orders.cancel', ['reason' => 'duplicate']],
    'confirm'  => ['orders.confirm', []],
    'reassign' => ['orders.reassign', ['assignee_id' => 1]],
]);

it('closes endpoint by guest', function (string $route, array $payload) {
    // factory Eloquent + fixture helper from trait (composition in action)
    $order = Order::factory()->pending()->create();

    $this->post(route($route, $order), $payload)
        ->assertRedirect(route('login'));
})->with('order_guest_endpoints');

// Inline dataset directly in the test - when the set is local and is not reused.
it('prohibits action of the wrong role', function (UserRole $role) {
    $this->actingAsRole($role);            // helper from trait ActsAsUser
    $order = Order::factory()->create();

    $this->post(route('orders.confirm', $order))->assertForbidden();
})->with([
    'viewer'   => UserRole::Viewer,
    'reporter' => UserRole::Reporter,
]);
