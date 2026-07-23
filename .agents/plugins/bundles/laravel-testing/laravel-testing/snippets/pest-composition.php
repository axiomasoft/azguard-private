<?php
// Source: anonymized production project

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 1. Переиспользуемый setUp-трейт (композиция вместо наследования)
|    tests/Support/Concerns/ActsAsUser.php
|--------------------------------------------------------------------------
| Вместо того чтобы раздувать TestCase или плодить подклассы под каждый
| сценарий доступа, поведение упаковывается в трейт. TestCase подключает
| только то, что нужно сьюту: `use ActsAsUser;` — и хелперы доступны во
| всех Pest-замыканиях (они забиндены на класс TestCase).
*/

namespace Tests\Support\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait ActsAsUser
{
    /**
     * Создаёт пользователя и назначает роль.
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
     * Авторизует пользователя с ролью и возвращает модель — частый префикс теста.
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
| Второй фикстур-трейт: SeedsUserRoles — узкая ответственность, не дублирует
| общий DatabaseSeeder; подключается только в сьютах, завязанных на роли.
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
| 2. TestCase = композиция трейтов (не глубокая иерархия наследования)
|    tests/TestCase.php
|--------------------------------------------------------------------------
| TestCase остаётся тонким: подключает фикстур-трейты и держит guard
| изоляции БД. Новое поведение добавляется новым трейтом, а не новым
| подклассом TestCase.
*/

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Concerns\ActsAsUser;
use Tests\Support\Concerns\SeedsUserRoles;

abstract class TestCase extends BaseTestCase
{
    use ActsAsUser;
    use SeedsUserRoles;

    // guard изоляции тестовой БД — см. секцию «Изоляция тестовой БД»
}

/*
|--------------------------------------------------------------------------
| 3. tests/Pest.php — единая «гигиена окружения» через beforeEach,
|    привязанная к директориям, а не повторяемая в каждом файле
|--------------------------------------------------------------------------
| Детерминизм времени и сети задаётся ОДИН раз на сьют:
|   - Http::preventStrayRequests() — любой неподделанный HTTP-вызов = падение
|     теста (а не молчаливый поход во внешний API)
|   - Sleep::fake() — sleep() в коде не тормозит прогон
|   - freezeTime() — Carbon::now() заморожен, ассерты времени стабильны
|   - Str::createRandomStringsNormally()/createUuidsNormally() — сбрасывают
|     возможный фейк из предыдущего теста (изоляция)
*/

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

// Feature: полная БД (RefreshDatabase) + фейки сети/времени.
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->beforeEach(function () {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();

        Http::fake([
            // dev-сервер ассетов: гасим, чтобы не плодить stray-запросы
            '127.0.0.1:5173/*' => Http::response(''),
        ]);
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature');

// Unit: те же фейки сети/времени, но БЕЗ RefreshDatabase (чистая логика).
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
| 4. Кастомные expectations и хелперы — расширяют API, не наследуют классы
|--------------------------------------------------------------------------
*/

use App\Enums\OrderStatus;

expect()->extend('toHaveOrderStatus', function (OrderStatus $status) {
    return $this->toHaveProperty('status', $status);
});

/*
|--------------------------------------------------------------------------
| 5. Датасет: один тест-сценарий × множество входов через ->with()
|    tests/Feature/Order/OrderEndpointAccessTest.php
|--------------------------------------------------------------------------
| Параметризация вместо копипасты тела теста. Именованный dataset() делает
| вывод падений читабельным («with data set "orders.cancel"»). Каждая
| строка — [route, payload]; тело теста одно.
*/

dataset('order_guest_endpoints', [
    'cancel'   => ['orders.cancel', ['reason' => 'duplicate']],
    'confirm'  => ['orders.confirm', []],
    'reassign' => ['orders.reassign', ['assignee_id' => 1]],
]);

it('закрывает endpoint от гостя', function (string $route, array $payload) {
    // фабрика Eloquent + фикстур-хелпер из трейта (композиция в действии)
    $order = Order::factory()->pending()->create();

    $this->post(route($route, $order), $payload)
        ->assertRedirect(route('login'));
})->with('order_guest_endpoints');

// Инлайн-датасет прямо в тесте — когда набор локален и не переиспользуется.
it('запрещает действие не той роли', function (UserRole $role) {
    $this->actingAsRole($role);            // хелпер из трейта ActsAsUser
    $order = Order::factory()->create();

    $this->post(route('orders.confirm', $order))->assertForbidden();
})->with([
    'viewer'   => UserRole::Viewer,
    'reporter' => UserRole::Reporter,
]);
