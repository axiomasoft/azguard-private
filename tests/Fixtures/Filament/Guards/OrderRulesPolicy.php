<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Guards;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Policies\Decides;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Business rules over the grants of the admin panel: a secret order or product is seen by nobody, a locked order is
 * deleted by nobody. The same rules as a query filter.
 */
final class OrderRulesPolicy implements FiltersAccessQueries
{
    #[Decides(OrderPermission::View)]
    public function view(Model $user, Order $order): bool
    {
        return ! $order->getAttribute('secret');
    }

    #[Decides(OrderPermission::Delete)]
    public function delete(Model $user, Order $order): bool
    {
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
