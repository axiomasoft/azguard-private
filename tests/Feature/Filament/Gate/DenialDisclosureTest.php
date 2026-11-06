<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\ArchivedOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Pages\ProbePage;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\Fixtures\Filament\Widgets\OrderCountWidget;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

final class SecretThrowingFilamentSource implements FiltersQueries, ProvidesGrants
{
    public static bool $throw = false;

    public static bool $throwOnResourceOnly = false;

    public function id(): string
    {
        return 'custom:secret-filament-source';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if (self::$throw || (self::$throwOnResourceOnly && $context->resource() !== null)) {
            throw new RuntimeException('SQLSTATE[42S02]: Secret table azguard_internal_grants');
        }

        return [];
    }

    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): ?AssignmentScopeSelection
    {
        return AssignmentScopeSelection::everywhere([]);
    }
}

final class SecretThrowingFilamentPolicy implements FiltersAccessQueries
{
    public static bool $throwOnDelete = false;

    #[Decides(OrderPermission::View)]
    public function view(Model $user, Order $order): bool
    {
        return ! $order->getAttribute('secret');
    }

    #[Decides(OrderPermission::Delete)]
    public function delete(Model $user, Order $order): bool
    {
        if (self::$throwOnDelete) {
            throw new RuntimeException('SQLSTATE[42S02]: Secret table azguard_internal_grants');
        }

        return ! $order->getAttribute('locked');
    }

    #[Decides(OrderPermission::ProductsView)]
    public function viewProduct(Model $user, Product $product): bool
    {
        return ! $product->getAttribute('secret');
    }

    public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
    {
        $allow = match ($request->permission()->local()) {
            'orders.view', 'products.view' => P::eq('secret', false),
            'orders.delete' => P::eq('locked', false),
            default => P::pass(),
        };

        return P::partition($allow, P::not($allow), P::deny());
    }
}

it('keeps the bulk denial notification generic', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    $component = Livewire::test(ListOrders::class)->selectTableRecords([1, 4])
        ->callAction(TestAction::make('delete')->table()->bulk());
    app(NotificationMailbox::class)->flush();
    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotificationsFromSession();
    $payload = json_encode($notifications->notifications->toArray(), JSON_THROW_ON_ERROR);
    expect($notifications->notifications)->toHaveCount(1)
        ->and($payload)->toContain('delete')->not->toContain('not_granted', 'source', 'trace', 'grant', 'scope', 'exception');
});

it('does not disclose exception messages, table names, or source and policy classes through FilamentGate or bulk notifications', function (): void {
    SecretThrowingFilamentSource::$throw = false;
    SecretThrowingFilamentPolicy::$throwOnDelete = false;

    GateWorld::prepare();
    FilamentFixture::$guardPermissions = [
        OrderPermission::class,
        ArchivedOrderPermission::class,
        new SecretThrowingFilamentSource,
    ];
    FilamentFixture::$guardPolicies = [
        SecretThrowingFilamentPolicy::class,
    ];
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    $member = GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']);
    GateWorld::serve($member);
    $order = Order::query()->findOrFail(1);

    SecretThrowingFilamentPolicy::$throwOnDelete = true;
    $policyGateResponse = FilamentGate::resource(OrderResource::class, 'delete', $order);
    expect($policyGateResponse->allowed())->toBeFalse()
        ->and((string) $policyGateResponse->message())->not->toContain(
            'SQLSTATE',
            '42S02',
            'azguard_internal_grants',
            'Secret table',
            SecretThrowingFilamentPolicy::class,
            'SecretThrowingFilamentPolicy',
        )
        ->and((string) $policyGateResponse->code())->not->toContain(
            'SQLSTATE',
            'azguard_internal_grants',
            'SecretThrowingFilamentPolicy',
        );

    Livewire::test(ListOrders::class)->selectTableRecords([1, 2])
        ->callAction(TestAction::make('delete')->table()->bulk());
    app(NotificationMailbox::class)->flush();
    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotificationsFromSession();
    $policyPayload = json_encode($notifications->notifications->toArray(), JSON_THROW_ON_ERROR);
    expect($notifications->notifications)->toHaveCount(1)
        ->and($policyPayload)->toContain('delete')
        ->not->toContain(
            'SQLSTATE',
            '42S02',
            'azguard_internal_grants',
            'Secret table',
            SecretThrowingFilamentPolicy::class,
            'SecretThrowingFilamentPolicy',
            'policy_error',
        );

    SecretThrowingFilamentPolicy::$throwOnDelete = false;
    SecretThrowingFilamentSource::$throwOnResourceOnly = true;
    Livewire::test(ListOrders::class)->selectTableRecords([1, 2])
        ->callAction(TestAction::make('delete')->table()->bulk());
    app(NotificationMailbox::class)->flush();
    $sourceNotifications = new Notifications;
    $sourceNotifications->mount();
    $sourceNotifications->pullNotificationsFromSession();
    $sourcePayload = json_encode($sourceNotifications->notifications->toArray(), JSON_THROW_ON_ERROR);
    expect($sourceNotifications->notifications)->toHaveCount(1)
        ->and($sourcePayload)->toContain('delete')
        ->not->toContain(
            'SQLSTATE',
            '42S02',
            'azguard_internal_grants',
            'Secret table',
            SecretThrowingFilamentSource::class,
            'SecretThrowingFilamentSource',
            'custom:secret-filament-source',
            'source_error',
        );

    SecretThrowingFilamentSource::$throwOnResourceOnly = false;
    SecretThrowingFilamentSource::$throw = true;
    $sourceGateResponse = FilamentGate::resource(OrderResource::class, 'delete', $order);
    $pageGateResponse = FilamentGate::page(ProbePage::class);
    $widgetGateResponse = FilamentGate::widget(OrderCountWidget::class);
    foreach ([$sourceGateResponse, $pageGateResponse, $widgetGateResponse] as $gateResponse) {
        expect($gateResponse->allowed())->toBeFalse()
            ->and((string) $gateResponse->message())->not->toContain(
                'SQLSTATE',
                '42S02',
                'azguard_internal_grants',
                'Secret table',
                SecretThrowingFilamentSource::class,
                'SecretThrowingFilamentSource',
                'custom:secret-filament-source',
            )
            ->and((string) $gateResponse->code())->not->toContain(
                'SQLSTATE',
                'azguard_internal_grants',
                'SecretThrowingFilamentSource',
                'custom:secret-filament-source',
            );
    }

    $this->actingAs($member);
    $httpResponse = $this->get('/admin/orders');
    $httpResponse->assertForbidden();
    expect($httpResponse->getContent())->not->toContain(
        'SQLSTATE',
        '42S02',
        'azguard_internal_grants',
        'Secret table',
        SecretThrowingFilamentSource::class,
        'SecretThrowingFilamentSource',
        'custom:secret-filament-source',
        'source_error',
    );
});
