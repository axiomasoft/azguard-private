<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Guards\Shop\Permissions\OrderPermission;
use App\Http\Controllers\ShopOrderController;
use App\Models\User;
use AzGuard\Exceptions\MissingPermissionCheckException;
use AzGuard\Laravel\Http\Middleware\CheckPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AzGuardHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_permission_attribute_answers_403_without_the_permission_and_200_with_it(): void
    {
        $member = User::factory()->create(['email' => 'member@example.com']);
        $manager = User::factory()->create(['email' => 'manager@example.com']);

        $this->actingAs($member)->get('/shop/orders/refund')->assertForbidden();
        $this->actingAs($manager)->get('/shop/orders/refund')->assertOk()->assertSeeText('refunded');
        $this->actingAs($member)->get('/shop/orders')->assertOk()->assertSeeText('orders');
    }

    public function test_strict_mode_rejects_an_unchecked_action(): void
    {
        Route::middleware(['web', 'auth', 'azguard.panel:shop'])->get('/shop/unchecked', static fn (): string => 'unchecked');
        $this->actingAs(User::factory()->create(['email' => 'member@example.com']));
        $this->withoutExceptionHandling();
        $this->expectException(MissingPermissionCheckException::class);

        $this->get('/shop/unchecked');
    }

    public function test_strict_mode_rejects_an_excluded_attribute(): void
    {
        Route::middleware(['web', 'auth', 'azguard.panel:shop'])
            ->get('/shop/excluded', [ShopOrderController::class, 'refund'])
            ->withoutMiddleware(CheckPermission::using(OrderPermission::Refund));
        $this->actingAs(User::factory()->create(['email' => 'member@example.com']));
        $this->withoutExceptionHandling();
        $this->expectException(MissingPermissionCheckException::class);

        $this->get('/shop/excluded');
    }

    public function test_strict_mode_answers_403_in_production_when_the_check_is_excluded(): void
    {
        Route::middleware(['web', 'auth', 'azguard.panel:shop'])
            ->get('/shop/excluded', [ShopOrderController::class, 'refund'])
            ->withoutMiddleware(CheckPermission::using(OrderPermission::Refund));
        app()->detectEnvironment(static fn (): string => 'production');

        $this->actingAs(User::factory()->create(['email' => 'member@example.com']))->getJson('/shop/excluded')->assertForbidden();
    }

    public function test_the_router_applies_the_attribute_where_laravel_reads_controller_middleware_attributes(): void
    {
        $route = Route::getRoutes()->getByAction(ShopOrderController::class.'@refund');
        $applied = in_array('azguard.can:App\Guards\Shop\Permissions\OrderPermission::Refund', $route?->gatherMiddleware() ?? [], true);

        $this->assertSame(class_exists(Middleware::class), $applied);
    }
}
